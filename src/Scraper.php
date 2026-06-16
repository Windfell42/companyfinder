<?php
namespace CompanyFinder;

use DOMDocument;
use DOMXPath;

/**
 * Generic scraper for BizBuySell / BizQuest style "businesses for sale"
 * search result pages.
 *
 * Both sites are server-rendered with schema.org structured data and a set of
 * predictable listing-card markup, so the scraper tries multiple extraction
 * strategies in order of reliability:
 *
 *   1. JSON-LD <script type="application/ld+json"> ItemList / Product blocks.
 *   2. Inline JSON state blobs (__NEXT_DATA__ / window.__INITIAL_STATE__).
 *   3. DOM listing cards via XPath as a last resort.
 *
 * Anything that matches an exclusion keyword is dropped before it is returned.
 */
class Scraper
{
    /**
     * @param array<string,mixed> $httpConfig
     * @param string[]            $excludeKeywords
     */
    public function __construct(
        private array $httpConfig,
        private array $excludeKeywords,
        private $logger = null,
    ) {}

    /**
     * Scrape every configured source page and return normalized listings.
     *
     * @param array<string,array<string,mixed>> $sources
     * @return array<int,array<string,mixed>>
     */
    public function scrape(array $sources): array
    {
        $all = [];
        foreach ($sources as $key => $cfg) {
            $this->log("=== Scraping {$cfg['label']} ===");
            $listings = $this->scrapeSource($key, $cfg);
            $this->log(sprintf('  %d listings kept from %s', count($listings), $cfg['label']));
            $all = array_merge($all, $listings);
        }
        return $all;
    }

    /**
     * @param array<string,mixed> $cfg
     * @return array<int,array<string,mixed>>
     */
    private function scrapeSource(string $source, array $cfg): array
    {
        $listings = [];
        $maxPages = (int) ($cfg['max_pages'] ?? 1);

        for ($page = 1; $page <= $maxPages; $page++) {
            $url = $this->pageUrl($cfg['search_url'], $page);
            $this->log("  GET $url");
            $html = $this->fetch($url);
            if ($html === null) {
                $this->log('  request failed, stopping pagination for this source');
                break;
            }

            $found = $this->parse($html, $source, $cfg['base']);
            if (!$found) {
                $this->log('  no listings parsed on this page, stopping');
                break;
            }

            foreach ($this->removeExcluded($found) as $listing) {
                $listings[$listing['external_id']] = $listing;
            }

            if ($page < $maxPages) {
                sleep((int) ($this->httpConfig['delay_seconds'] ?? 1));
            }
        }

        return array_values($listings);
    }

    private function pageUrl(string $base, int $page): string
    {
        if ($page <= 1) {
            return $base;
        }
        // Both sites paginate with a trailing /N/ segment or ?page=N. Use the
        // query form which both accept.
        $sep = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . 'page=' . $page;
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($this->requestUrl($url));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => (int) ($this->httpConfig['timeout'] ?? 30),
            CURLOPT_USERAGENT      => $this->httpConfig['user_agent'] ?? 'CompanyFinder/1.0',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            $this->log('  HTTP error: ' . ($err ?: "status $code"));
            return null;
        }
        return (string) $body;
    }

    /**
     * Wrap the target URL in the configured scraping-API / proxy template when
     * one is set, so requests can clear anti-bot walls that a direct fetch
     * cannot. Falls back to the raw URL otherwise.
     */
    private function requestUrl(string $url): string
    {
        $template = $this->httpConfig['proxy_template'] ?? null;
        if (!$template) {
            return $url;
        }
        return str_replace('{url}', rawurlencode($url), $template);
    }

    /**
     * Run all extraction strategies and return whatever the first productive
     * one yields.
     *
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $html, string $source, string $base): array
    {
        $listings = $this->parseJsonLd($html, $source, $base);
        if ($listings) {
            return $listings;
        }
        $listings = $this->parseInlineState($html, $source, $base);
        if ($listings) {
            return $listings;
        }
        return $this->parseDom($html, $source, $base);
    }

    /** @return array<int,array<string,mixed>> */
    private function parseJsonLd(string $html, string $source, string $base): array
    {
        if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return [];
        }

        $out = [];
        foreach ($m[1] as $block) {
            $data = json_decode(trim($block), true);
            if (!is_array($data)) {
                continue;
            }
            // Normalize to a flat list of nodes to inspect.
            $nodes = isset($data[0]) ? $data : [$data];
            foreach ($nodes as $node) {
                $items = $this->itemsFromNode($node);
                foreach ($items as $item) {
                    $listing = $this->listingFromSchema($item, $source, $base);
                    if ($listing) {
                        $out[] = $listing;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Pull product/listing nodes out of a JSON-LD node, handling ItemList
     * wrappers and @graph collections.
     *
     * @param array<string,mixed> $node
     * @return array<int,array<string,mixed>>
     */
    private function itemsFromNode(array $node): array
    {
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            $out = [];
            foreach ($node['@graph'] as $g) {
                $out = array_merge($out, $this->itemsFromNode($g));
            }
            return $out;
        }
        $type = $node['@type'] ?? '';
        if ($type === 'ItemList' && isset($node['itemListElement'])) {
            $out = [];
            foreach ($node['itemListElement'] as $el) {
                $item = $el['item'] ?? $el;
                if (is_array($item)) {
                    $out[] = $item;
                }
            }
            return $out;
        }
        if (in_array($type, ['Product', 'Offer', 'LocalBusiness', 'Service'], true)) {
            return [$node];
        }
        return [];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    private function listingFromSchema(array $item, string $source, string $base): ?array
    {
        $title = $item['name'] ?? null;
        if (!$title) {
            return null;
        }

        $url   = $item['url'] ?? ($item['@id'] ?? '');
        $url   = $this->absoluteUrl((string) $url, $base);
        $desc  = $item['description'] ?? '';

        // Price can live on the node or in a nested offers object.
        $price = $this->extractPrice($item['offers'] ?? ($item['price'] ?? null));

        $location = $this->extractLocation($item);

        return $this->normalizeListing([
            'source'        => $source,
            'title'         => (string) $title,
            'url'           => $url,
            'description'   => (string) $desc,
            'business_type' => (string) ($item['category'] ?? ''),
            'location'      => $location,
            'price'         => $price,
            'cash_flow'     => null,
            'gross_revenue' => null,
        ]);
    }

    /** @param array<string,mixed> $item */
    private function extractLocation(array $item): string
    {
        $addr = $item['address'] ?? null;
        if (is_array($addr)) {
            $parts = array_filter([
                $addr['addressLocality'] ?? null,
                $addr['addressRegion'] ?? null,
            ]);
            return implode(', ', $parts);
        }
        if (is_string($addr)) {
            return $addr;
        }
        return (string) ($item['areaServed'] ?? '');
    }

    private function extractPrice(mixed $offers): ?float
    {
        if (is_string($offers) || is_numeric($offers)) {
            return $this->parseMoney($offers);
        }
        if (is_array($offers)) {
            $p = $offers['price'] ?? ($offers['lowPrice'] ?? ($offers[0]['price'] ?? null));
            return $this->parseMoney($p);
        }
        return null;
    }

    /**
     * Parse a monetary value from numbers or human strings. Handles things
     * like 1250000, "1250000", "$1,250,000", "$1.25M", "950K", "$1.2 million".
     */
    private function parseMoney(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return $v > 0 ? (float) $v : null;
        }
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        if (!preg_match('/([\d][\d,]*(?:\.\d+)?)\s*(million|mil|thousand|m|k)?/i', $v, $m)) {
            return null;
        }
        $num = (float) str_replace(',', '', $m[1]);
        $suffix = strtolower($m[2] ?? '');
        if (in_array($suffix, ['m', 'mil', 'million'], true)) {
            $num *= 1_000_000;
        } elseif (in_array($suffix, ['k', 'thousand'], true)) {
            $num *= 1_000;
        }
        return $num > 0 ? $num : null;
    }

    /**
     * Parse Next.js / Redux style inline JSON state. Returns whatever listing
     * objects we can recognise by their key shape.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parseInlineState(string $html, string $source, string $base): array
    {
        $patterns = [
            '#<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#is',
            '#window\.__INITIAL_STATE__\s*=\s*(\{.*?\});#is',
        ];
        foreach ($patterns as $re) {
            if (!preg_match($re, $html, $m)) {
                continue;
            }
            $data = json_decode(trim($m[1]), true);
            if (!is_array($data)) {
                continue;
            }
            $out = [];
            $this->harvestListings($data, $source, $base, $out);
            if ($out) {
                return $out;
            }
        }
        return [];
    }

    /**
     * Recursively walk a decoded JSON structure looking for objects that
     * look like business listings (have a title/name plus a price-ish field).
     *
     * @param array<string,mixed> $out
     */
    private function harvestListings(mixed $node, string $source, string $base, array &$out): void
    {
        if (!is_array($node)) {
            return;
        }

        $title = $node['headerName'] ?? ($node['name'] ?? ($node['title'] ?? null));
        $hasPriceKey = array_key_exists('askingPrice', $node) || array_key_exists('price', $node)
            || array_key_exists('cashFlow', $node);
        if (is_string($title) && $title !== '' && $hasPriceKey) {
            $url = $node['listingUrl'] ?? ($node['url'] ?? ($node['detailUrl'] ?? ''));
            $listing = $this->normalizeListing([
                'source'        => $source,
                'title'         => $title,
                'url'           => $this->absoluteUrl((string) $url, $base),
                'description'   => (string) ($node['description'] ?? ($node['teaser'] ?? '')),
                'business_type' => (string) ($node['industry'] ?? ($node['category'] ?? '')),
                'location'      => (string) ($node['location'] ?? ($node['city'] ?? '')),
                'price'         => $this->parseMoney($node['askingPrice'] ?? ($node['price'] ?? null)),
                'cash_flow'     => $this->parseMoney($node['cashFlow'] ?? ($node['cash_flow'] ?? null)),
                'gross_revenue' => $this->parseMoney($node['grossRevenue'] ?? ($node['revenue'] ?? ($node['grossIncome'] ?? null))),
            ]);
            if ($listing) {
                $out[] = $listing;
            }
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->harvestListings($child, $source, $base, $out);
            }
        }
    }

    /**
     * Last-resort DOM scrape of listing cards. Selectors are intentionally
     * broad; sites change markup often, so this is best-effort.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parseDom(string $html, string $source, string $base): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        // Anchor on links to detail pages, which both sites expose.
        $nodes = $xp->query("//a[contains(@href,'business-for-sale') or contains(@href,'/Business-Opportunity') or contains(@href,'businesses-for-sale')]");
        if ($nodes === false) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($nodes as $a) {
            $title = trim(preg_replace('/\s+/', ' ', $a->textContent));
            $href  = $a->getAttribute('href');
            if ($title === '' || strlen($title) < 6 || $href === '') {
                continue;
            }

            // A card usually has several links (image, title, "details"); key
            // on the listing id so we only emit each card once, and prefer the
            // longest anchor text as the title.
            $id = $this->idFromUrl($this->absoluteUrl($href, $base));
            if (isset($seen[$id]) && strlen($title) <= strlen($seen[$id]['title'])) {
                continue;
            }

            // Climb to the nearest ancestor that contains pricing text so we
            // can read the figures that sit alongside the link.
            $cardText = $this->cardText($a);

            $listing = $this->normalizeListing([
                'source'        => $source,
                'title'         => $title,
                'url'           => $this->absoluteUrl($href, $base),
                'description'   => '',
                'business_type' => '',
                'location'      => $this->extractLocationText($cardText),
                'price'         => $this->extractLabeledMoney($cardText, ['asking price', 'price', 'asking']) ?? $this->largestMoney($cardText),
                'cash_flow'     => $this->extractLabeledMoney($cardText, ['cash flow', 'sde', 'seller\'s discretionary', 'net profit', 'net income']),
                'gross_revenue' => $this->extractLabeledMoney($cardText, ['gross revenue', 'gross income', 'gross sales', 'revenue', 'sales']),
            ]);
            if ($listing) {
                $seen[$id] = $listing;
            }
        }
        return array_values($seen);
    }

    /**
     * Text of the nearest ancestor of $node that looks like a listing card
     * (contains a dollar figure), with whitespace collapsed. Falls back to the
     * immediate parent so we always return something to scan.
     */
    private function cardText(\DOMNode $node): string
    {
        $best = $node->parentNode;
        $cur = $node->parentNode;
        for ($i = 0; $i < 6 && $cur !== null; $i++) {
            $text = $cur->textContent ?? '';
            if (str_contains($text, '$') && strlen($text) < 2000) {
                $best = $cur;
                break;
            }
            $cur = $cur->parentNode;
        }
        return trim(preg_replace('/\s+/', ' ', $best?->textContent ?? ''));
    }

    /**
     * Find the first money figure that follows one of the given labels, e.g.
     * "Cash Flow: $410,000". Labels are matched case-insensitively.
     */
    private function extractLabeledMoney(string $text, array $labels): ?float
    {
        foreach ($labels as $label) {
            $re = '/' . preg_quote($label, '/') . '\s*[:\-]?\s*\$?\s*([\d][\d,]*(?:\.\d+)?\s*(?:million|mil|thousand|m|k)?)/i';
            if (preg_match($re, $text, $m)) {
                $val = $this->parseMoney($m[1]);
                if ($val !== null) {
                    return $val;
                }
            }
        }
        return null;
    }

    /** Largest dollar figure in a blob of text — a decent guess for asking price. */
    private function largestMoney(string $text): ?float
    {
        if (!preg_match_all('/\$\s*([\d][\d,]*(?:\.\d+)?\s*(?:million|mil|thousand|m|k)?)/i', $text, $m)) {
            return null;
        }
        $values = array_filter(array_map(fn($s) => $this->parseMoney($s), $m[1]));
        return $values ? max($values) : null;
    }

    /** Pull a recognised DFW city out of free card text, as "City, TX". */
    private function extractLocationText(string $text): string
    {
        $city = Geo::cityIn($text);
        return $city !== null ? $city . ', TX' : '';
    }

    /**
     * Fill in derived fields (external_id, distance, coords) and stamp the
     * scrape time. Returns null when the listing is unusable.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private function normalizeListing(array $data): ?array
    {
        if (empty($data['url']) || empty($data['title'])) {
            return null;
        }

        $data['external_id'] = $this->idFromUrl($data['url']);
        $data['business_type'] = $data['business_type'] !== ''
            ? $data['business_type']
            : 'Uncategorized';

        $coords = Geo::coordsFor($data['location'] ?? '');
        $data['latitude']  = $coords[0] ?? null;
        $data['longitude'] = $coords[1] ?? null;
        $data['distance_mi'] = null; // filled by the importer using the anchor
        $data['is_sample'] = 0;
        $data['scraped_at'] = date('c');

        return $data;
    }

    private function idFromUrl(string $url): string
    {
        if (preg_match('#/(\d{4,})#', $url, $m)) {
            return $m[1];
        }
        return substr(sha1($url), 0, 16);
    }

    private function absoluteUrl(string $url, string $base): string
    {
        if ($url === '' || str_starts_with($url, 'http')) {
            return $url;
        }
        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Drop any listing whose text matches a configured exclusion keyword.
     * Shared by the live scraper and the offline HTML importer so both apply
     * the same Restaurant/franchise rules.
     *
     * @param array<int,array<string,mixed>> $listings
     * @return array<int,array<string,mixed>>
     */
    public function removeExcluded(array $listings): array
    {
        return array_values(array_filter($listings, fn($l) => !$this->isExcluded($l)));
    }

    /** @param array<string,mixed> $listing */
    private function isExcluded(array $listing): bool
    {
        $haystack = strtolower(($listing['title'] ?? '') . ' ' . ($listing['description'] ?? '') . ' ' . ($listing['business_type'] ?? ''));
        foreach ($this->excludeKeywords as $word) {
            if (str_contains($haystack, strtolower($word))) {
                return true;
            }
        }
        return false;
    }

    private function log(string $msg): void
    {
        if (is_callable($this->logger)) {
            ($this->logger)($msg);
        }
    }
}

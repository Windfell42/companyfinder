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

            // Diagnostics: what did we actually get back?
            $this->log(sprintf(
                '  fetched %d bytes (ld+json: %s, listing links: %s)',
                strlen($html),
                str_contains($html, 'application/ld+json') ? 'yes' : 'no',
                preg_match('/business-(?:opportunity|for-sale)/i', $html) ? 'yes' : 'no'
            ));
            // A suspiciously small body is almost always an error/interstitial,
            // not the listings page — show it so the cause is visible.
            if (strlen($html) < 2000) {
                $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
                $this->log('  body snippet: ' . mb_substr($snippet, 0, 500));
            }

            $found = $this->parse($html, $source, $cfg['base']);
            $this->log('  parsed ' . count($found) . ' listing(s) on page ' . $page);
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
        $bd = $this->httpConfig['brightdata'] ?? null;
        if (is_array($bd) && !empty($bd['enabled']) && !empty($bd['api_key'])) {
            return $this->brightDataFetch($url, $bd);
        }

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
     * Fetch a URL through Bright Data's Web Unlocker API, which renders the page
     * and clears anti-bot protection, returning the raw HTML.
     *
     * @param array<string,mixed> $bd the brightdata config block
     */
    private function brightDataFetch(string $url, array $bd): ?string
    {
        $endpoint = $bd['endpoint'] ?? 'https://api.brightdata.com/request';
        $this->log('  BrightData GET ' . $url);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) ($bd['timeout'] ?? 90),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $bd['api_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode(array_filter([
                'zone'    => $bd['zone'] ?? 'web_unlocker1',
                'url'     => $url,
                'format'  => 'raw',
                // Pin to a country (Akamai often hard-blocks non-US IPs).
                'country' => $bd['country'] ?? 'us',
            ])),
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            // Surface Bright Data's message (e.g. a bad zone name) to the log,
            // but never the API key.
            $detail = $err ?: ('status ' . $code . ' ' . substr((string) $body, 0, 200));
            $this->log('  BrightData error: ' . $detail);
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
     * Run every extraction strategy and merge their output by listing id.
     *
     * The strategies are complementary: BizBuySell's JSON-LD carries clean
     * titles, prices and locations but no cash flow, while the DOM cards carry
     * cash flow. Merging fills the gaps. Rows with no financials at all (stray
     * navigation/city links picked up by the DOM pass) are dropped.
     *
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $html, string $source, string $base): array
    {
        $byId = [];
        $strategies = [
            $this->parseJsonLd($html, $source, $base),
            $this->parseInlineState($html, $source, $base),
            $this->parseDom($html, $source, $base),
        ];
        foreach ($strategies as $listings) {
            foreach ($listings as $l) {
                $id = $l['external_id'];
                $byId[$id] = isset($byId[$id]) ? $this->mergeListing($byId[$id], $l) : $l;
            }
        }

        // Keep only rows that have at least one financial figure; this filters
        // out non-listing links the DOM pass may have matched.
        return array_values(array_filter($byId, fn($l) =>
            $l['price'] !== null || $l['cash_flow'] !== null || $l['gross_revenue'] !== null));
    }

    /**
     * Combine two records for the same listing, preferring existing non-empty
     * values and filling in anything that was missing.
     *
     * @param array<string,mixed> $a existing
     * @param array<string,mixed> $b new
     * @return array<string,mixed>
     */
    private function mergeListing(array $a, array $b): array
    {
        // Keep the existing title (JSON-LD is processed first and is the clean,
        // authoritative business name); only fill it in if it was missing.
        if (empty($a['title']) && !empty($b['title'])) {
            $a['title'] = $b['title'];
        }
        foreach (['url', 'description', 'location'] as $k) {
            if (empty($a[$k]) && !empty($b[$k])) {
                $a[$k] = $b[$k];
            }
        }
        if ((empty($a['business_type']) || $a['business_type'] === 'Uncategorized') && !empty($b['business_type']) && $b['business_type'] !== 'Uncategorized') {
            $a['business_type'] = $b['business_type'];
        }
        foreach (['price', 'cash_flow', 'gross_revenue', 'latitude', 'longitude'] as $k) {
            if (($a[$k] ?? null) === null && ($b[$k] ?? null) !== null) {
                $a[$k] = $b[$k];
            }
        }
        return $a;
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
            // BizBuySell wraps listings in an `about` array of ListItems, other
            // sites use ItemList/@graph/Product directly. Rather than guess the
            // shape, deep-walk for anything that looks like a Product node.
            $products = [];
            $this->collectSchemaProducts($data, $products);
            foreach ($products as $item) {
                $listing = $this->listingFromSchema($item, $source, $base);
                if ($listing) {
                    $out[] = $listing;
                }
            }
        }
        return $out;
    }

    /**
     * Recursively gather schema.org Product nodes from a decoded JSON-LD tree,
     * regardless of how they are nested (about / itemListElement / item /
     * @graph / arrays).
     *
     * @param array<int,array<string,mixed>> $out
     */
    private function collectSchemaProducts(mixed $node, array &$out): void
    {
        if (!is_array($node)) {
            return;
        }
        $type = $node['@type'] ?? null;
        $isProduct = is_string($type)
            ? in_array($type, ['Product', 'LocalBusiness', 'Service'], true)
            : (is_array($type) && array_intersect($type, ['Product', 'LocalBusiness', 'Service']));
        if ($isProduct && !empty($node['name'])) {
            $out[] = $node;
            // Don't recurse into a product's own sub-objects.
            return;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collectSchemaProducts($child, $out);
            }
        }
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
        // BizBuySell nests the address under offers.availableAtOrFrom; other
        // shapes put it directly on the item. Check both.
        $addr = $item['address']
            ?? ($item['offers']['availableAtOrFrom']['address'] ?? null);

        if (is_array($addr)) {
            $parts = array_filter(array_map('trim', [
                (string) ($addr['addressLocality'] ?? ''),
                (string) ($addr['addressRegion'] ?? ''),
            ]), fn($p) => $p !== '');
            return implode(', ', $parts);
        }
        if (is_string($addr)) {
            return trim($addr);
        }
        return trim((string) ($item['areaServed'] ?? ''));
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
        // Hint UTF-8 so accented characters / en-dashes aren't mangled (e.g.
        // "–" turning into "â€“"). Without this libxml assumes Latin-1.
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        // Anchor on links to detail pages. Match case-insensitively and cover
        // BizBuySell's "/business-opportunity/<id>/" as well as the
        // "...-for-sale" variants other pages use.
        $lower = "translate(@href,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')";
        $nodes = $xp->query("//a[contains($lower,'business-opportunity') or contains($lower,'business-for-sale') or contains($lower,'businesses-for-sale')]");
        if ($nodes === false) {
            return [];
        }

        $seen = [];
        foreach ($nodes as $a) {
            $href = $a->getAttribute('href');
            if ($href === '') {
                continue;
            }
            // One card has several links (image, title, "details"); emit each
            // listing id once.
            $id = $this->idFromUrl($this->absoluteUrl($href, $base));
            if (isset($seen[$id])) {
                continue;
            }

            $card  = $this->cardContainer($a);
            $title = $this->extractTitle($xp, $card, $a);
            if ($title === '' || mb_strlen($title) < 4) {
                continue;
            }

            $cardText = trim(preg_replace('/\s+/', ' ', $card?->textContent ?? $a->textContent));

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
     * The business name for a card. Prefer a heading element (the actual title
     * markup) over the anchor's full text, which on BizBuySell wraps the entire
     * card and would otherwise pull in price/description text.
     */
    private function extractTitle(DOMXPath $xp, ?\DOMNode $card, \DOMNode $anchor): string
    {
        if ($card !== null) {
            $heads = $xp->query('.//h1|.//h2|.//h3|.//h4', $card);
            if ($heads !== false) {
                foreach ($heads as $h) {
                    $t = $this->cleanTitle($h->textContent);
                    if ($t !== '' && mb_strlen($t) >= 4) {
                        return $t;
                    }
                }
            }
            // Fall back to an element whose class hints it is the title/name.
            $named = $xp->query(".//*[contains(translate(@class,'TITLENAME','titlename'),'title') or contains(translate(@class,'TITLENAME','titlename'),'name')]", $card);
            if ($named !== false) {
                foreach ($named as $n) {
                    $t = $this->cleanTitle($n->textContent);
                    if ($t !== '' && mb_strlen($t) >= 4) {
                        return $t;
                    }
                }
            }
        }
        return $this->cleanTitle($anchor->textContent);
    }

    /**
     * Tidy a candidate title: collapse whitespace and cut off anything from the
     * first financial label / price onward (a guard for when the only text we
     * have is a whole-card blob).
     */
    private function cleanTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title));
        $parts = preg_split('/\s*(?:Asking Price|Cash Flow|Sales Revenue|Gross Revenue|Gross Income|Established|\$)/i', $title);
        $title = trim($parts[0] ?? $title);
        if (mb_strlen($title) > 120) {
            $title = mb_substr($title, 0, 117) . '…';
        }
        return $title;
    }

    /**
     * The nearest ancestor of $node that looks like a listing card (contains a
     * dollar figure). Falls back to the immediate parent.
     */
    private function cardContainer(\DOMNode $node): ?\DOMNode
    {
        $cur = $node->parentNode;
        for ($i = 0; $i < 6 && $cur !== null; $i++) {
            $text = $cur->textContent ?? '';
            if (str_contains($text, '$') && strlen($text) < 2000) {
                return $cur;
            }
            $cur = $cur->parentNode;
        }
        return $node->parentNode;
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
        // The sources rarely expose a category, so infer one from the text
        // when none was supplied.
        $type = trim((string) ($data['business_type'] ?? ''));
        if ($type === '' || strtolower($type) === 'uncategorized') {
            $type = Classifier::classify($data['title'], $data['description'] ?? '');
        }
        $data['business_type'] = $type;

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

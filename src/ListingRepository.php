<?php
namespace CompanyFinder;

use PDO;

/**
 * Read/write access to the listings table plus the query-time filtering and
 * trend aggregation the dashboard needs.
 */
class ListingRepository
{
    public function __construct(private PDO $pdo, private array $anchor) {}

    /**
     * Insert or update a batch of scraped listings. Computes distance from the
     * anchor when coordinates are present. Returns the number of rows written.
     *
     * @param array<int,array<string,mixed>> $listings
     */
    /**
     * Insert or update a batch of scraped listings, tracking changes over time.
     *
     * On first sight a row gets first_seen = last_seen = now and a history
     * entry. On a repeat sighting last_seen advances; if the asking price
     * changed, previous_price / price_changed_at are stamped and a new history
     * entry is recorded. first_seen is never overwritten. A null incoming price
     * is treated as "unknown" and leaves the stored price untouched.
     *
     * Returns the number of rows written.
     *
     * @param array<int,array<string,mixed>> $listings
     */
    public function upsertMany(array $listings): int
    {
        $find = $this->pdo->prepare('SELECT price, first_seen, previous_price, price_changed_at FROM listings WHERE source = :source AND external_id = :external_id');

        $insert = $this->pdo->prepare(<<<SQL
            INSERT INTO listings
                (source, external_id, title, url, description, business_type,
                 location, state, price, cash_flow, gross_revenue,
                 latitude, longitude, distance_mi, is_sample, scraped_at,
                 first_seen, last_seen, previous_price, price_changed_at)
            VALUES
                (:source, :external_id, :title, :url, :description, :business_type,
                 :location, :state, :price, :cash_flow, :gross_revenue,
                 :latitude, :longitude, :distance_mi, :is_sample, :scraped_at,
                 :first_seen, :last_seen, :previous_price, :price_changed_at)
        SQL);

        $update = $this->pdo->prepare(<<<SQL
            UPDATE listings SET
                title=:title, url=:url, description=:description, business_type=:business_type,
                location=:location, state=:state, price=:price, cash_flow=:cash_flow,
                gross_revenue=:gross_revenue, latitude=:latitude, longitude=:longitude,
                distance_mi=:distance_mi, is_sample=:is_sample, scraped_at=:scraped_at,
                last_seen=:last_seen, previous_price=:previous_price, price_changed_at=:price_changed_at
            WHERE source=:source AND external_id=:external_id
        SQL);

        $history = $this->pdo->prepare('INSERT INTO listing_history (source, external_id, price, cash_flow, gross_revenue, recorded_at) VALUES (:source, :external_id, :price, :cash_flow, :gross_revenue, :recorded_at)');

        $count = 0;
        $this->pdo->beginTransaction();
        foreach ($listings as $l) {
            $now      = $l['scraped_at'] ?? date('c');
            $distance = $this->distanceFor($l);
            $newPrice = $l['price'] ?? null;

            $find->execute([':source' => $l['source'], ':external_id' => $l['external_id']]);
            $existing = $find->fetch();

            $common = [
                ':source'        => $l['source'],
                ':external_id'   => $l['external_id'],
                ':title'         => $l['title'],
                ':url'           => $l['url'],
                ':description'   => $l['description'] ?? null,
                ':business_type' => $l['business_type'] ?? null,
                ':location'      => $l['location'] ?? null,
                ':state'         => $l['state'] ?? 'TX',
                ':cash_flow'     => $l['cash_flow'] ?? null,
                ':gross_revenue' => $l['gross_revenue'] ?? null,
                ':latitude'      => $l['latitude'] ?? null,
                ':longitude'     => $l['longitude'] ?? null,
                ':distance_mi'   => $distance,
                ':is_sample'     => $l['is_sample'] ?? 0,
                ':scraped_at'    => $now,
            ];

            if (!$existing) {
                $insert->execute($common + [
                    ':price'            => $newPrice,
                    ':first_seen'       => $now,
                    ':last_seen'        => $now,
                    ':previous_price'   => null,
                    ':price_changed_at' => null,
                ]);
                $this->recordHistory($history, $l, $newPrice, $now);
            } else {
                $oldPrice = $existing['price'] !== null ? (float) $existing['price'] : null;
                // Keep the existing price if no new price was found this time.
                $effectivePrice = $newPrice ?? $oldPrice;
                $priceChanged = $newPrice !== null && $oldPrice !== null
                    && abs($oldPrice - (float) $newPrice) > 0.5;

                $update->execute($common + [
                    ':price'            => $effectivePrice,
                    ':last_seen'        => $now,
                    ':previous_price'   => $priceChanged ? $oldPrice : $existing['previous_price'],
                    ':price_changed_at' => $priceChanged ? $now : $existing['price_changed_at'],
                ]);
                if ($priceChanged) {
                    $this->recordHistory($history, $l, $newPrice, $now);
                }
            }
            $count++;
        }
        $this->pdo->commit();
        return $count;
    }

    /** @param array<string,mixed> $l */
    private function recordHistory(\PDOStatement $stmt, array $l, ?float $price, string $when): void
    {
        $stmt->execute([
            ':source'        => $l['source'],
            ':external_id'   => $l['external_id'],
            ':price'         => $price,
            ':cash_flow'     => $l['cash_flow'] ?? null,
            ':gross_revenue' => $l['gross_revenue'] ?? null,
            ':recorded_at'   => $when,
        ]);
    }

    /** @param array<string,mixed> $l */
    private function distanceFor(array $l): ?float
    {
        if (isset($l['distance_mi']) && $l['distance_mi'] !== null) {
            return (float) $l['distance_mi'];
        }
        if (isset($l['latitude'], $l['longitude']) && $l['latitude'] !== null && $l['longitude'] !== null) {
            return round(Geo::milesBetween(
                (float) $l['latitude'], (float) $l['longitude'],
                $this->anchor['lat'], $this->anchor['lng']
            ), 1);
        }
        return null;
    }

    /**
     * Query listings with the dashboard's filter set.
     *
     * Supported filter keys:
     *   contains       string[]  every term must appear somewhere
     *   not_contains   string[]  no term may appear
     *   source         string    'bizbuysell' | 'bizquest' | ''
     *   business_type  string    exact category match
     *   min_price/max_price/min_cash_flow  numeric
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['source'])) {
            $where[] = 'source = :source';
            $params[':source'] = $filters['source'];
        }
        if (!empty($filters['business_type'])) {
            $where[] = 'business_type = :btype';
            $params[':btype'] = $filters['business_type'];
        }
        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $where[] = 'price >= :min_price';
            $params[':min_price'] = (float) $filters['min_price'];
        }
        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $where[] = 'price <= :max_price';
            $params[':max_price'] = (float) $filters['max_price'];
        }
        if (isset($filters['min_cash_flow']) && $filters['min_cash_flow'] !== '') {
            $where[] = 'cash_flow >= :min_cf';
            $params[':min_cf'] = (float) $filters['min_cash_flow'];
        }
        // "New only": first seen on/after the cutoff and never re-seen since.
        if (!empty($filters['new_only']) && !empty($filters['new_cutoff'])) {
            $where[] = 'first_seen = last_seen AND first_seen >= :new_cutoff';
            $params[':new_cutoff'] = $filters['new_cutoff'];
        }
        // "Price changed only": a previous price has been recorded.
        if (!empty($filters['changed_only'])) {
            $where[] = 'previous_price IS NOT NULL';
        }
        // "Starred only": flagged as interesting.
        if (!empty($filters['starred_only'])) {
            $where[] = 'is_starred = 1';
        }

        $i = 0;
        foreach ($this->terms($filters['contains'] ?? []) as $term) {
            $key = ':inc' . $i++;
            $where[] = "(LOWER(title) LIKE $key OR LOWER(description) LIKE $key OR LOWER(business_type) LIKE $key OR LOWER(location) LIKE $key OR LOWER(COALESCE(tags,'')) LIKE $key)";
            $params[$key] = '%' . strtolower($term) . '%';
        }
        $j = 0;
        foreach ($this->terms($filters['not_contains'] ?? []) as $term) {
            $key = ':exc' . $j++;
            $where[] = "(LOWER(title) NOT LIKE $key AND LOWER(description) NOT LIKE $key AND LOWER(business_type) NOT LIKE $key AND LOWER(location) NOT LIKE $key AND LOWER(COALESCE(tags,'')) NOT LIKE $key)";
            $params[$key] = '%' . strtolower($term) . '%';
        }

        $sql = 'SELECT * FROM listings WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Normalize a comma/array keyword input into a clean list of terms.
     *
     * @param string|array<int,string> $value
     * @return array<int,string>
     */
    private function terms(string|array $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        $out = [];
        foreach ($value as $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * Aggregate trends for a set of (already-filtered) listing rows.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function trends(array $rows): array
    {
        $byType = [];
        foreach ($rows as $r) {
            $type = $r['business_type'] ?: 'Uncategorized';
            $byType[$type] ??= ['type' => $type, 'count' => 0, 'price_sum' => 0, 'price_n' => 0, 'cf_sum' => 0, 'cf_n' => 0];
            $byType[$type]['count']++;
            if ($r['price'] !== null) {
                $byType[$type]['price_sum'] += (float) $r['price'];
                $byType[$type]['price_n']++;
            }
            if ($r['cash_flow'] !== null) {
                $byType[$type]['cf_sum'] += (float) $r['cash_flow'];
                $byType[$type]['cf_n']++;
            }
        }
        foreach ($byType as &$t) {
            $t['avg_price']     = $t['price_n'] ? round($t['price_sum'] / $t['price_n']) : null;
            $t['avg_cash_flow'] = $t['cf_n'] ? round($t['cf_sum'] / $t['cf_n']) : null;
            unset($t['price_sum'], $t['price_n'], $t['cf_sum'], $t['cf_n']);
        }
        unset($t);
        usort($byType, fn($a, $b) => $b['count'] <=> $a['count']);

        // Price distribution buckets.
        $buckets = [
            '< $100K'      => [0, 100_000],
            '$100K–250K'   => [100_000, 250_000],
            '$250K–500K'   => [250_000, 500_000],
            '$500K–1M'     => [500_000, 1_000_000],
            '$1M–2.5M'     => [1_000_000, 2_500_000],
            '> $2.5M'      => [2_500_000, PHP_INT_MAX],
        ];
        $priceHistogram = [];
        foreach ($buckets as $label => [$lo, $hi]) {
            $priceHistogram[$label] = 0;
        }
        foreach ($rows as $r) {
            if ($r['price'] === null) {
                continue;
            }
            $p = (float) $r['price'];
            foreach ($buckets as $label => [$lo, $hi]) {
                if ($p >= $lo && $p < $hi) {
                    $priceHistogram[$label]++;
                    break;
                }
            }
        }

        return [
            'by_type'         => array_values($byType),
            'price_histogram' => $priceHistogram,
        ];
    }

    /** @return array<int,string> */
    public function businessTypes(): array
    {
        $rows = $this->pdo->query('SELECT DISTINCT business_type FROM listings WHERE business_type IS NOT NULL AND business_type <> "" ORDER BY business_type')->fetchAll();
        return array_map(fn($r) => $r['business_type'], $rows);
    }

    /**
     * Price (and figure) history for a single listing, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function history(string $source, string $externalId): array
    {
        $stmt = $this->pdo->prepare('SELECT price, cash_flow, gross_revenue, recorded_at FROM listing_history WHERE source = :source AND external_id = :external_id ORDER BY recorded_at ASC, id ASC');
        $stmt->execute([':source' => $source, ':external_id' => $externalId]);
        return $stmt->fetchAll();
    }

    /**
     * Update a listing's user-supplied metadata (interesting flag and/or free
     * text tags). Only the fields passed (non-null) are changed. Returns true
     * if a matching listing was found.
     */
    public function setUserMeta(string $source, string $externalId, ?bool $starred, ?string $tags): bool
    {
        $set = [];
        $params = [':source' => $source, ':external_id' => $externalId];
        if ($starred !== null) {
            $set[] = 'is_starred = :starred';
            $params[':starred'] = $starred ? 1 : 0;
        }
        if ($tags !== null) {
            $clean = trim($tags);
            $set[] = 'tags = :tags';
            $params[':tags'] = $clean === '' ? null : $clean;
        }
        if (!$set) {
            return false;
        }
        $stmt = $this->pdo->prepare('UPDATE listings SET ' . implode(', ', $set) . ' WHERE source = :source AND external_id = :external_id');
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /** @return array<int,string> distinct tags currently in use */
    public function tags(): array
    {
        $rows = $this->pdo->query("SELECT tags FROM listings WHERE tags IS NOT NULL AND tags <> ''")->fetchAll();
        $all = [];
        foreach ($rows as $r) {
            foreach (explode(',', $r['tags']) as $t) {
                $t = trim($t);
                if ($t !== '') {
                    $all[$t] = true;
                }
            }
        }
        $list = array_keys($all);
        sort($list);
        return $list;
    }

    /** @param string|null $newCutoff ISO timestamp; rows first seen on/after it count as new */
    public function counts(?string $newCutoff = null): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) c FROM listings')->fetch()['c'];
        $sample = (int) $this->pdo->query('SELECT COUNT(*) c FROM listings WHERE is_sample = 1')->fetch()['c'];
        $latest = $this->pdo->query('SELECT MAX(scraped_at) m FROM listings')->fetch()['m'];
        $changed = (int) $this->pdo->query('SELECT COUNT(*) c FROM listings WHERE previous_price IS NOT NULL')->fetch()['c'];
        $starred = (int) $this->pdo->query('SELECT COUNT(*) c FROM listings WHERE is_starred = 1')->fetch()['c'];

        $new = 0;
        if ($newCutoff !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) c FROM listings WHERE first_seen = last_seen AND first_seen >= :cut');
            $stmt->execute([':cut' => $newCutoff]);
            $new = (int) $stmt->fetch()['c'];
        }

        return [
            'total'        => $total,
            'sample'       => $sample,
            'live'         => $total - $sample,
            'new'          => $new,
            'price_changed' => $changed,
            'starred'      => $starred,
            'last_scraped' => $latest,
        ];
    }

    public function clearSamples(): int
    {
        $this->pdo->exec('DELETE FROM listing_history WHERE (source, external_id) IN (SELECT source, external_id FROM listings WHERE is_sample = 1)');
        return (int) $this->pdo->exec('DELETE FROM listings WHERE is_sample = 1');
    }

    public function clearLive(): int
    {
        $this->pdo->exec('DELETE FROM listing_history WHERE (source, external_id) IN (SELECT source, external_id FROM listings WHERE is_sample = 0)');
        return (int) $this->pdo->exec('DELETE FROM listings WHERE is_sample = 0');
    }

    /** Delete every listing and its history. Returns the number of listings removed. */
    public function clearAll(): int
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) c FROM listings')->fetch()['c'];
        $this->pdo->exec('DELETE FROM listing_history');
        $this->pdo->exec('DELETE FROM listings');
        return $before;
    }

    /**
     * Remove already-stored live listings that are out of region: explicitly in
     * another state, beyond the radius, or category/related-search links that
     * were mistakenly imported. Sample rows are left alone. Returns the count
     * removed.
     *
     * @param array{lat: float, lng: float} $anchor
     * @param array<int,string> $excludeLocationKeywords
     * @param array<int,string> $keepKeywords  substrings that exempt a listing (e.g. "property management")
     */
    public function deleteOutOfRegion(array $anchor, float $maxMi, string $state = 'TX', array $excludeLocationKeywords = [], array $keepKeywords = []): int
    {
        $rows = $this->pdo->query('SELECT id, source, external_id, url, title, business_type, location, latitude, longitude FROM listings WHERE is_sample = 0')->fetchAll();

        $kill = [];
        foreach ($rows as $r) {
            if ($this->isOutOfRegion($r, $anchor, $maxMi, $state, $excludeLocationKeywords, $keepKeywords)) {
                $kill[] = $r;
            }
        }
        if (!$kill) {
            return 0;
        }

        $this->pdo->beginTransaction();
        $delHist = $this->pdo->prepare('DELETE FROM listing_history WHERE source = :s AND external_id = :e');
        $delRow  = $this->pdo->prepare('DELETE FROM listings WHERE id = :id');
        foreach ($kill as $r) {
            $delHist->execute([':s' => $r['source'], ':e' => $r['external_id']]);
            $delRow->execute([':id' => $r['id']]);
        }
        $this->pdo->commit();

        return count($kill);
    }

    /**
     * @param array<string,mixed> $r
     * @param array{lat: float, lng: float} $anchor
     * @param array<int,string> $excludeLocationKeywords
     * @param array<int,string> $keepKeywords
     */
    private function isOutOfRegion(array $r, array $anchor, float $maxMi, string $state, array $excludeLocationKeywords = [], array $keepKeywords = []): bool
    {
        // Category / related-search link mistakenly imported as a listing.
        if (str_contains(strtolower((string) ($r['url'] ?? '')), 'businesses-for-sale-in-')) {
            return true;
        }
        $loc = (string) ($r['location'] ?? '');

        // Exempt listings (e.g. property-management companies) from the
        // franchise/coverage-area location rule.
        $text = strtolower(($r['title'] ?? '') . ' ' . ($r['business_type'] ?? '') . ' ' . $loc);
        $exempt = false;
        foreach ($keepKeywords as $kw) {
            if ($kw !== '' && str_contains($text, strtolower($kw))) {
                $exempt = true;
                break;
            }
        }

        // Franchise / multi-location coverage area (e.g. "Available Nationwide").
        $locLower = strtolower($loc);
        if (!$exempt) {
            foreach ($excludeLocationKeywords as $kw) {
                if ($kw !== '' && str_contains($locLower, strtolower($kw))) {
                    return true;
                }
            }
        }
        // Explicit out-of-state location (e.g. "Madison, WI").
        if (preg_match('/,\s*([A-Za-z]{2})\b\s*$/', $loc, $m) && strtoupper($m[1]) !== strtoupper($state)) {
            return true;
        }
        // Known coordinates beyond the radius.
        if (($r['latitude'] ?? null) !== null && ($r['longitude'] ?? null) !== null) {
            $d = Geo::milesBetween((float) $r['latitude'], (float) $r['longitude'], $anchor['lat'], $anchor['lng']);
            if ($d > $maxMi) {
                return true;
            }
        }
        return false;
    }
}

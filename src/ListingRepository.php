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
    public function upsertMany(array $listings): int
    {
        $sql = <<<SQL
            INSERT INTO listings
                (source, external_id, title, url, description, business_type,
                 location, state, price, cash_flow, gross_revenue,
                 latitude, longitude, distance_mi, is_sample, scraped_at)
            VALUES
                (:source, :external_id, :title, :url, :description, :business_type,
                 :location, :state, :price, :cash_flow, :gross_revenue,
                 :latitude, :longitude, :distance_mi, :is_sample, :scraped_at)
            ON CONFLICT(source, external_id) DO UPDATE SET
                title=excluded.title, url=excluded.url, description=excluded.description,
                business_type=excluded.business_type, location=excluded.location,
                state=excluded.state, price=excluded.price, cash_flow=excluded.cash_flow,
                gross_revenue=excluded.gross_revenue, latitude=excluded.latitude,
                longitude=excluded.longitude, distance_mi=excluded.distance_mi,
                is_sample=excluded.is_sample, scraped_at=excluded.scraped_at
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $count = 0;
        foreach ($listings as $l) {
            $distance = $this->distanceFor($l);
            $stmt->execute([
                ':source'        => $l['source'],
                ':external_id'   => $l['external_id'],
                ':title'         => $l['title'],
                ':url'           => $l['url'],
                ':description'   => $l['description'] ?? null,
                ':business_type' => $l['business_type'] ?? null,
                ':location'      => $l['location'] ?? null,
                ':state'         => $l['state'] ?? 'TX',
                ':price'         => $l['price'] ?? null,
                ':cash_flow'     => $l['cash_flow'] ?? null,
                ':gross_revenue' => $l['gross_revenue'] ?? null,
                ':latitude'      => $l['latitude'] ?? null,
                ':longitude'     => $l['longitude'] ?? null,
                ':distance_mi'   => $distance,
                ':is_sample'     => $l['is_sample'] ?? 0,
                ':scraped_at'    => $l['scraped_at'] ?? date('c'),
            ]);
            $count++;
        }
        return $count;
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

        $i = 0;
        foreach ($this->terms($filters['contains'] ?? []) as $term) {
            $key = ':inc' . $i++;
            $where[] = "(LOWER(title) LIKE $key OR LOWER(description) LIKE $key OR LOWER(business_type) LIKE $key OR LOWER(location) LIKE $key)";
            $params[$key] = '%' . strtolower($term) . '%';
        }
        $j = 0;
        foreach ($this->terms($filters['not_contains'] ?? []) as $term) {
            $key = ':exc' . $j++;
            $where[] = "(LOWER(title) NOT LIKE $key AND LOWER(description) NOT LIKE $key AND LOWER(business_type) NOT LIKE $key AND LOWER(location) NOT LIKE $key)";
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

    public function counts(): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) c FROM listings')->fetch()['c'];
        $sample = (int) $this->pdo->query('SELECT COUNT(*) c FROM listings WHERE is_sample = 1')->fetch()['c'];
        $latest = $this->pdo->query('SELECT MAX(scraped_at) m FROM listings')->fetch()['m'];
        return ['total' => $total, 'sample' => $sample, 'live' => $total - $sample, 'last_scraped' => $latest];
    }

    public function clearSamples(): int
    {
        return (int) $this->pdo->exec('DELETE FROM listings WHERE is_sample = 1');
    }

    public function clearLive(): int
    {
        return (int) $this->pdo->exec('DELETE FROM listings WHERE is_sample = 0');
    }

    /** Delete every listing. Returns the number of rows removed. */
    public function clearAll(): int
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) c FROM listings')->fetch()['c'];
        $this->pdo->exec('DELETE FROM listings');
        return $before;
    }
}

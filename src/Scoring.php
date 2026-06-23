<?php
namespace CompanyFinder;

/**
 * Opportunity scoring.
 *
 * Each listing is scored 0-100 from three normalized components:
 *   - price:     lower asking price is better (cheaper to acquire)
 *   - cash_flow: higher seller's discretionary earnings / cash flow is better
 *   - proximity: closer to the configured anchor (Plano) is better
 *
 * Normalization is min-max across the current result set, so the score is
 * relative to the listings being compared. Weights are configurable and are
 * normalized so they always sum to 1.
 *
 * After the weighted score, keyword adjustments are applied: each distinct
 * "boost" keyword present in a listing adds points, each "penalty" keyword
 * subtracts them (default ±10 per keyword).
 */
class Scoring
{
    /**
     * @param array<string,float>  $weights
     * @param array<int,string>    $boostWords    keywords that add points when present
     * @param array<int,string>    $penaltyWords  keywords that subtract points when present
     */
    public function __construct(
        private array $weights,
        private array $boostWords = [],
        private array $penaltyWords = [],
        private float $pointsPerKeyword = 10.0,
    ) {
        $sum = array_sum($this->weights) ?: 1.0;
        foreach ($this->weights as $k => $v) {
            $this->weights[$k] = $v / $sum;
        }
    }

    /**
     * Annotate each listing row with a `score` (0-100) and `score_breakdown`.
     * Operates on and returns the same array of rows.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public function apply(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }

        $prices    = $this->column($rows, 'price');
        $cashFlows = $this->column($rows, 'cash_flow');
        $distances = $this->column($rows, 'distance_mi');

        [$pMin, $pMax] = $this->range($prices);
        [$cMin, $cMax] = $this->range($cashFlows);
        [$dMin, $dMax] = $this->range($distances);

        foreach ($rows as &$row) {
            // Lower price is better => invert the normalized value.
            $priceScore = isset($row['price']) && $row['price'] !== null
                ? 1 - $this->normalize((float) $row['price'], $pMin, $pMax)
                : 0.0;

            // Higher cash flow is better.
            $cashScore = isset($row['cash_flow']) && $row['cash_flow'] !== null
                ? $this->normalize((float) $row['cash_flow'], $cMin, $cMax)
                : 0.0;

            // Closer is better => invert.
            $proxScore = isset($row['distance_mi']) && $row['distance_mi'] !== null
                ? 1 - $this->normalize((float) $row['distance_mi'], $dMin, $dMax)
                : 0.0;

            $total = $this->weights['price'] * $priceScore
                + $this->weights['cash_flow'] * $cashScore
                + $this->weights['proximity'] * $proxScore;

            $base = round($total * 100, 1);

            // Keyword adjustments: ± points per distinct keyword present.
            $adjust = $this->keywordAdjustment($row);

            // Allow the boosted/penalized score past 0-100 so it meaningfully
            // re-ranks, but never below 0.
            $row['score'] = max(0.0, round($base + $adjust, 1));
            $row['score_breakdown'] = [
                'price'     => round($priceScore * 100, 1),
                'cash_flow' => round($cashScore * 100, 1),
                'proximity' => round($proxScore * 100, 1),
                'keywords'  => $adjust,
            ];
        }
        unset($row);

        return $rows;
    }

    /**
     * Net keyword adjustment for one listing: + points for each present boost
     * keyword, − points for each present penalty keyword.
     *
     * @param array<string,mixed> $row
     */
    private function keywordAdjustment(array $row): float
    {
        if (!$this->boostWords && !$this->penaltyWords) {
            return 0.0;
        }
        $haystack = strtolower(implode(' ', [
            $row['title'] ?? '',
            $row['description'] ?? '',
            $row['business_type'] ?? '',
            $row['location'] ?? '',
            $row['tags'] ?? '',
        ]));

        $adjust = 0.0;
        foreach ($this->boostWords as $w) {
            if ($w !== '' && str_contains($haystack, strtolower($w))) {
                $adjust += $this->pointsPerKeyword;
            }
        }
        foreach ($this->penaltyWords as $w) {
            if ($w !== '' && str_contains($haystack, strtolower($w))) {
                $adjust -= $this->pointsPerKeyword;
            }
        }
        return $adjust;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,float> */
    private function column(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (isset($r[$key]) && $r[$key] !== null) {
                $out[] = (float) $r[$key];
            }
        }
        return $out;
    }

    /** @param array<int,float> $values @return array{0: float, 1: float} */
    private function range(array $values): array
    {
        if (!$values) {
            return [0.0, 0.0];
        }
        return [min($values), max($values)];
    }

    private function normalize(float $value, float $min, float $max): float
    {
        if ($max <= $min) {
            return 0.5; // no spread; treat everything as neutral
        }
        $v = ($value - $min) / ($max - $min);
        return max(0.0, min(1.0, $v));
    }
}

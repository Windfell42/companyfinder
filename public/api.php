<?php
/**
 * JSON API backing the dashboard.
 *
 *   GET api.php?action=search    -> filtered + scored listings, trends, meta
 *   GET api.php?action=meta      -> available business types, counts
 *
 * All filter parameters are read from the query string so results are
 * shareable/bookmarkable.
 */

use CompanyFinder\Database;
use CompanyFinder\ListingRepository;
use CompanyFinder\Scoring;

$config = require __DIR__ . '/../src/bootstrap.php';

(new CompanyFinder\Auth($config['auth']))->requireApi();

header('Content-Type: application/json');

$db   = new Database($config['db_path']);
$repo = new ListingRepository($db->pdo(), $config['anchor']);

$action = $_GET['action'] ?? 'search';

// Timestamp before which a listing is no longer considered "new".
$daysNew   = (int) ($config['days_new'] ?? 7);
$newCutoff = date('c', strtotime("-{$daysNew} days"));

if ($action === 'meta') {
    echo json_encode([
        'business_types' => $repo->businessTypes(),
        'tags'           => $repo->tags(),
        'counts'         => $repo->counts($newCutoff),
        'region'         => $config['region'],
        'anchor'         => $config['anchor'],
        'exclude'        => $config['exclude_keywords'],
        'days_new'       => $daysNew,
    ]);
    exit;
}

if ($action === 'history') {
    $rows = $repo->history((string) ($_GET['source'] ?? ''), (string) ($_GET['external_id'] ?? ''));
    echo json_encode(['history' => $rows]);
    exit;
}

// --- search ---------------------------------------------------------------

$filters = [
    'source'        => $_GET['source'] ?? '',
    'business_type' => $_GET['business_type'] ?? '',
    'min_price'     => $_GET['min_price'] ?? '',
    'max_price'     => $_GET['max_price'] ?? '',
    'min_cash_flow' => $_GET['min_cash_flow'] ?? '',
    'contains'      => $_GET['contains'] ?? '',
    'not_contains'  => $_GET['not_contains'] ?? '',
    'new_only'      => !empty($_GET['new_only']),
    'new_cutoff'    => $newCutoff,
    'changed_only'  => !empty($_GET['changed_only']),
    'starred_only'  => !empty($_GET['starred_only']),
];

// Scoring weights are optional UI overrides; fall back to config defaults.
$weights = $config['score_weights'];
foreach (['price', 'cash_flow', 'proximity'] as $w) {
    if (isset($_GET['w_' . $w]) && is_numeric($_GET['w_' . $w])) {
        $weights[$w] = (float) $_GET['w_' . $w];
    }
}

// Keyword score adjustments (+/- points per present keyword).
$terms = static function (string $csv): array {
    return array_values(array_filter(array_map('trim', explode(',', $csv)), fn($t) => $t !== ''));
};
$boostWords   = $terms($_GET['boost'] ?? '');
$penaltyWords = $terms($_GET['penalty'] ?? '');

$rows    = $repo->search($filters);
$trends  = $repo->trends($rows);
$scoring = new Scoring($weights, $boostWords, $penaltyWords);
$rows    = $scoring->apply($rows);

// Annotate each row with change-tracking info for the UI.
foreach ($rows as &$row) {
    // NEW = seen for the first time and never re-seen since (first_seen still
    // equals last_seen), and recent. Once an update re-sees a listing,
    // last_seen advances and the NEW tag clears.
    $row['is_new'] = isset($row['first_seen'], $row['last_seen'])
        && $row['first_seen'] !== null
        && $row['first_seen'] === $row['last_seen']
        && $row['first_seen'] >= $newCutoff;
    if (isset($row['previous_price']) && $row['previous_price'] !== null && $row['price'] !== null) {
        $delta = (float) $row['price'] - (float) $row['previous_price'];
        $row['price_change'] = [
            'previous' => (float) $row['previous_price'],
            'delta'    => $delta,
            'pct'      => $row['previous_price'] != 0 ? round($delta / (float) $row['previous_price'] * 100, 1) : null,
            'at'       => $row['price_changed_at'] ?? null,
        ];
    } else {
        $row['price_change'] = null;
    }
}
unset($row);

// Sort by score descending so the best opportunities float to the top.
usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);

$sort = $_GET['sort'] ?? 'score';
if ($sort === 'newest') {
    usort($rows, fn($a, $b) => ($b['first_seen'] ?? '') <=> ($a['first_seen'] ?? ''));
} elseif ($sort !== 'score') {
    $dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 1 : -1;
    usort($rows, fn($a, $b) => $dir * (($a[$sort] ?? 0) <=> ($b[$sort] ?? 0)));
}

echo json_encode([
    'listings' => $rows,
    'trends'   => $trends,
    'count'    => count($rows),
    'weights'  => $weights,
    'anchor'   => $config['anchor'],
]);

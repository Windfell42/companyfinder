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

if ($action === 'meta') {
    echo json_encode([
        'business_types' => $repo->businessTypes(),
        'counts'         => $repo->counts(),
        'region'         => $config['region'],
        'anchor'         => $config['anchor'],
        'exclude'        => $config['exclude_keywords'],
    ]);
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
];

// Scoring weights are optional UI overrides; fall back to config defaults.
$weights = $config['score_weights'];
foreach (['price', 'cash_flow', 'proximity'] as $w) {
    if (isset($_GET['w_' . $w]) && is_numeric($_GET['w_' . $w])) {
        $weights[$w] = (float) $_GET['w_' . $w];
    }
}

$rows    = $repo->search($filters);
$trends  = $repo->trends($rows);
$scoring = new Scoring($weights);
$rows    = $scoring->apply($rows);

// Sort by score descending so the best opportunities float to the top.
usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);

$sort = $_GET['sort'] ?? 'score';
if ($sort !== 'score') {
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

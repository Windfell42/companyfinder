<?php
/**
 * "Update Now" endpoint: fetch the configured source search pages live through
 * Bright Data's Web Unlocker API, parse them, drop excluded listings, and
 * upsert (with change tracking). POST-only and auth-guarded.
 *
 * Optional POST field:
 *   source   bizbuysell | bizquest   (limit to one source)
 *
 * Requires a Bright Data API key (see brightdata.local.php / BRIGHTDATA_API_KEY).
 */

use CompanyFinder\Auth;
use CompanyFinder\Database;
use CompanyFinder\ListingRepository;
use CompanyFinder\Scraper;

$config = require __DIR__ . '/../src/bootstrap.php';

(new Auth($config['auth']))->requireApi();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$bd = $config['brightdata'] ?? [];
if (empty($bd['enabled']) || empty($bd['api_key'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Bright Data API key is not configured. Add it to brightdata.local.php or the BRIGHTDATA_API_KEY environment variable.']);
    exit;
}

// Live fetches can take a while; don't let PHP time out mid-run.
set_time_limit(0);

$sources = $config['sources'];
$only = $_POST['source'] ?? '';
if ($only !== '') {
    if (!isset($sources[$only])) {
        http_response_code(400);
        echo json_encode(['error' => "Unknown source: $only"]);
        exit;
    }
    $sources = [$only => $sources[$only]];
}

// Route fetches through Bright Data and keep pauses minimal (the API paces
// itself).
$http = $config['http'];
$http['brightdata']    = $bd;
$http['delay_seconds'] = 0;

$log = [];
$logger = function (string $m) use (&$log) { $log[] = $m; };

$db   = new Database($config['db_path']);
$repo = new ListingRepository($db->pdo(), $config['anchor']);
$scraper = new Scraper($http, $config['exclude_keywords'], $logger);

$listings = $scraper->scrape($sources);
$written  = $repo->upsertMany($listings);

$daysNew   = (int) ($config['days_new'] ?? 7);
$newCutoff = date('c', strtotime("-{$daysNew} days"));

echo json_encode([
    'imported' => $written,
    'found'    => count($listings),
    'log'      => $log,
    'counts'   => $repo->counts($newCutoff),
]);

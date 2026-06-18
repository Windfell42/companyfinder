<?php
/**
 * "Update Now" endpoint — fetches ONE results page per request so the browser
 * can drive pagination and we never exceed the web server's request timeout.
 *
 * POST fields:
 *   source   bizbuysell | bizquest   (required)
 *   page     1-based page number      (default 1)
 *
 * Returns JSON: { source, page, parsed, imported, ids[], max_pages, fetched,
 *                 counts, log[] }. Always returns JSON, even on error.
 *
 * Requires a Bright Data API key (see brightdata.local.php / BRIGHTDATA_API_KEY).
 */

use CompanyFinder\Auth;
use CompanyFinder\Database;
use CompanyFinder\ListingRepository;
use CompanyFinder\Scraper;

$config = require __DIR__ . '/../src/bootstrap.php';

// Guarantee a JSON body even on a fatal error, so the client never sees an
// HTML error page it can't parse.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Server error: ' . $e['message']]);
    }
});

(new Auth($config['auth']))->requireApi();

header('Content-Type: application/json');

try {
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

    $source = $_POST['source'] ?? '';
    if (!isset($config['sources'][$source])) {
        http_response_code(400);
        echo json_encode(['error' => "Unknown or missing source: $source"]);
        exit;
    }
    $cfg = $config['sources'][$source];
    $page = max(1, (int) ($_POST['page'] ?? 1));

    set_time_limit(0); // a single page is quick, but be safe

    $http = $config['http'];
    $http['brightdata'] = $bd;

    $log = [];
    $logger = function (string $m) use (&$log) { $log[] = $m; };

    $db   = new Database($config['db_path']);
    $repo = new ListingRepository($db->pdo(), $config['anchor']);
    $scraper = new Scraper($http, $config['exclude_keywords'], $logger);

    $logger("=== {$cfg['label']} · page {$page} ===");
    $res = $scraper->fetchPageListings($source, $cfg, $page);
    $listings = $res['listings'];

    $rf = $config['region_filter'] ?? [];
    if (!empty($rf['enabled'])) {
        $before = count($listings);
        $listings = $scraper->filterRegion($listings, $config['anchor'], (float) ($rf['max_radius_mi'] ?? 75), $rf['state'] ?? 'TX');
        $logger(sprintf('  region filter: kept %d of %d', count($listings), $before));
    }

    $written = $repo->upsertMany($listings);

    $daysNew   = (int) ($config['days_new'] ?? 7);
    $newCutoff = date('c', strtotime("-{$daysNew} days"));

    echo json_encode([
        'source'    => $source,
        'page'      => $page,
        'parsed'    => $res['parsed'],
        'fetched'   => $res['fetched'],
        'imported'  => $written,
        'ids'       => array_values(array_map(fn($l) => $l['external_id'], $listings)),
        'max_pages' => (int) ($cfg['max_pages'] ?? 1),
        'counts'    => $repo->counts($newCutoff),
        'log'       => $log,
    ]);
} catch (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => 'Update failed: ' . $e->getMessage()]);
}

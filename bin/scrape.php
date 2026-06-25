<?php
/**
 * CLI scraper runner.
 *
 *   php bin/scrape.php                 scrape all configured sources
 *   php bin/scrape.php --source=bizquest   scrape just one source
 *   php bin/scrape.php --clear-samples     remove seeded sample rows first
 *
 * Live scraping requires outbound network access to bizbuysell.com and
 * bizquest.com. If that is blocked (or the sites change their markup), the
 * scraper logs the failure and writes nothing; the dashboard keeps showing
 * whatever is already in the database (including sample data).
 */

use CompanyFinder\Database;
use CompanyFinder\ListingRepository;
use CompanyFinder\Scraper;

$config = require __DIR__ . '/../src/bootstrap.php';

$opts = getopt('', ['source::', 'clear-samples', 'clear-all', 'brightdata']);

$db   = new Database($config['db_path']);
$repo = new ListingRepository($db->pdo(), $config['anchor']);

if (isset($opts['clear-all'])) {
    $n = $repo->clearAll();
    fwrite(STDOUT, "Removed $n rows (entire database cleared).\n");
} elseif (isset($opts['clear-samples'])) {
    $n = $repo->clearSamples();
    fwrite(STDOUT, "Removed $n sample rows.\n");
}

$sources = $config['sources'];
if (!empty($opts['source'])) {
    $key = $opts['source'];
    if (!isset($sources[$key])) {
        fwrite(STDERR, "Unknown source: $key\n");
        exit(1);
    }
    $sources = [$key => $sources[$key]];
}

$http = $config['http'];
if (isset($opts['brightdata'])) {
    if (empty($config['brightdata']['api_key'])) {
        fwrite(STDERR, "No Bright Data API key configured (set BRIGHTDATA_API_KEY or brightdata.local.php).\n");
        exit(1);
    }
    $http['brightdata'] = $config['brightdata'];
    fwrite(STDOUT, "Fetching via Bright Data Web Unlocker.\n");
}

$logger = fn(string $m) => fwrite(STDOUT, $m . "\n");
$scraper = new Scraper($http, $config['exclude_keywords'], $logger, $config['exclude_exceptions'] ?? []);

$listings = $scraper->scrape($sources);

$rf = $config['region_filter'] ?? [];
if (!empty($rf['enabled'])) {
    $before = count($listings);
    $listings = $scraper->filterRegion($listings, $config['anchor'], (float) ($rf['max_radius_mi'] ?? 75), $rf['state'] ?? 'TX', $rf['exclude_location_keywords'] ?? [], $config['exclude_exceptions']['franchise'] ?? []);
    fwrite(STDOUT, sprintf("Region filter: kept %d of %d\n", count($listings), $before));
}

$written = $repo->upsertMany($listings);

fwrite(STDOUT, "\nDone. Upserted $written listings.\n");

$counts = $repo->counts();
fwrite(STDOUT, sprintf(
    "Database now holds %d listings (%d live, %d sample).\n",
    $counts['total'], $counts['live'], $counts['sample']
));

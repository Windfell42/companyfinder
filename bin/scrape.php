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

$opts = getopt('', ['source::', 'clear-samples']);

$db   = new Database($config['db_path']);
$repo = new ListingRepository($db->pdo(), $config['anchor']);

if (isset($opts['clear-samples'])) {
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

$logger = fn(string $m) => fwrite(STDOUT, $m . "\n");
$scraper = new Scraper($config['http'], $config['exclude_keywords'], $logger);

$listings = $scraper->scrape($sources);
$written  = $repo->upsertMany($listings);

fwrite(STDOUT, "\nDone. Upserted $written listings.\n");

$counts = $repo->counts();
fwrite(STDOUT, sprintf(
    "Database now holds %d listings (%d live, %d sample).\n",
    $counts['total'], $counts['live'], $counts['sample']
));

<?php
/**
 * Offline HTML importer.
 *
 * Parses business listings out of HTML files you have already saved (e.g.
 * "Save Page As" from your browser, or pages pulled by a tool that can clear
 * the anti-bot wall) and imports them with the same parsing, exclusion, geo
 * and scoring pipeline the live scraper uses.
 *
 * This is the most dependable path on IONOS shared hosting, because the
 * fetching — the only step a bot wall can block — happens in a real browser,
 * while PHP does what it is good at: parsing.
 *
 *   php bin/import.php page.html --source=bizbuysell
 *   php bin/import.php saved_pages/ --source=bizquest      (all *.html in dir)
 *
 * --source defaults to bizbuysell and only sets the listing's `source` label
 * and base URL for resolving relative links.
 */

use CompanyFinder\Database;
use CompanyFinder\ListingRepository;
use CompanyFinder\Scraper;

$config = require __DIR__ . '/../src/bootstrap.php';

$args = $argv;
array_shift($args);
$opts = [];
$paths = [];
foreach ($args as $a) {
    if (str_starts_with($a, '--source=')) {
        $opts['source'] = substr($a, strlen('--source='));
    } else {
        $paths[] = $a;
    }
}

if (!$paths) {
    fwrite(STDERR, "Usage: php bin/import.php <file|dir> [more...] --source=bizbuysell|bizquest\n");
    exit(1);
}

$source = $opts['source'] ?? 'bizbuysell';
if (!isset($config['sources'][$source])) {
    fwrite(STDERR, "Unknown source: $source\n");
    exit(1);
}
$base = $config['sources'][$source]['base'];

// Expand directories into their *.html / *.htm files.
$files = [];
foreach ($paths as $p) {
    if (is_dir($p)) {
        foreach (glob(rtrim($p, '/') . '/*.{html,htm}', GLOB_BRACE) ?: [] as $f) {
            $files[] = $f;
        }
    } elseif (is_file($p)) {
        $files[] = $p;
    } else {
        fwrite(STDERR, "Skipping (not found): $p\n");
    }
}

if (!$files) {
    fwrite(STDERR, "No HTML files to import.\n");
    exit(1);
}

$db   = new Database($config['db_path']);
$repo = new ListingRepository($db->pdo(), $config['anchor']);
$scraper = new Scraper($config['http'], $config['exclude_keywords']);

$total = 0;
foreach ($files as $file) {
    $html = file_get_contents($file);
    if ($html === false) {
        fwrite(STDERR, "Could not read $file\n");
        continue;
    }
    $listings = $scraper->removeExcluded($scraper->parse($html, $source, $base));
    $written = $repo->upsertMany($listings);
    $total += $written;
    fwrite(STDOUT, sprintf("  %-40s %d listings\n", basename($file), $written));
}

fwrite(STDOUT, "\nDone. Upserted $total listings from " . count($files) . " file(s).\n");
$counts = $repo->counts();
fwrite(STDOUT, sprintf(
    "Database now holds %d listings (%d live, %d sample).\n",
    $counts['total'], $counts['live'], $counts['sample']
));

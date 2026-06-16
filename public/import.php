<?php
/**
 * Web upload endpoint for the offline HTML import path.
 *
 * Accepts a POST with either uploaded HTML file(s) (`files[]`) or pasted HTML
 * (`html`), runs them through the same parsing / exclusion / geo / scoring
 * pipeline as the CLI importer, and returns a JSON summary. This lets you
 * refresh the data from the dashboard without shell or FTP access.
 *
 * POST fields:
 *   source   bizbuysell | bizquest   (which site the HTML came from)
 *   files[]  one or more uploaded .html files
 *   html     pasted page source (optional alternative to a file)
 */

use CompanyFinder\Database;
use CompanyFinder\ListingRepository;
use CompanyFinder\Scraper;

$config = require __DIR__ . '/../src/bootstrap.php';

(new CompanyFinder\Auth($config['auth']))->requireApi();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$source = $_POST['source'] ?? 'bizbuysell';
if (!isset($config['sources'][$source])) {
    http_response_code(400);
    echo json_encode(['error' => "Unknown source: $source"]);
    exit;
}
$base = $config['sources'][$source]['base'];

// Gather HTML documents from uploads and/or the pasted textarea.
$maxBytes = 12 * 1024 * 1024; // 12 MB per document
$docs = [];   // [label => html]
$errors = [];

if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    $files = $_FILES['files'];
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "$name: upload error code {$files['error'][$i]}";
            continue;
        }
        if (($files['size'][$i] ?? 0) > $maxBytes) {
            $errors[] = "$name: exceeds 12 MB limit";
            continue;
        }
        $content = @file_get_contents($files['tmp_name'][$i]);
        if ($content === false || trim($content) === '') {
            $errors[] = "$name: unreadable or empty";
            continue;
        }
        $docs[$name] = $content;
    }
}

$pasted = trim($_POST['html'] ?? '');
if ($pasted !== '') {
    if (strlen($pasted) > $maxBytes) {
        $errors[] = 'pasted HTML exceeds 12 MB limit';
    } else {
        $docs['(pasted HTML)'] = $pasted;
    }
}

if (!$docs) {
    http_response_code(400);
    echo json_encode([
        'error'  => 'No HTML provided. Upload at least one file or paste page source.',
        'errors' => $errors,
    ]);
    exit;
}

$db      = new Database($config['db_path']);
$repo    = new ListingRepository($db->pdo(), $config['anchor']);
$scraper = new Scraper($config['http'], $config['exclude_keywords']);

$results = [];
$totalImported = 0;
foreach ($docs as $label => $html) {
    $parsed   = $scraper->parse($html, $source, $base);
    $kept     = $scraper->removeExcluded($parsed);
    $written  = $repo->upsertMany($kept);
    $totalImported += $written;
    $results[] = [
        'file'     => $label,
        'parsed'   => count($parsed),
        'excluded' => count($parsed) - count($kept),
        'imported' => $written,
        // A small preview of what was actually extracted, so mis-parses
        // (e.g. missing price/cash flow) are easy to spot.
        'preview'  => array_map(fn($l) => [
            'title'         => $l['title'],
            'price'         => $l['price'],
            'cash_flow'     => $l['cash_flow'],
            'gross_revenue' => $l['gross_revenue'],
            'location'      => $l['location'],
        ], array_slice($kept, 0, 10)),
    ];
}

echo json_encode([
    'source'   => $source,
    'imported' => $totalImported,
    'files'    => $results,
    'errors'   => $errors,
    'counts'   => $repo->counts(),
]);

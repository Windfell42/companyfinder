<?php
/**
 * Save user-supplied metadata for a listing: an "interesting" star and/or
 * free-text tags. POST-only and auth-guarded.
 *
 * POST fields:
 *   source       bizbuysell | bizquest
 *   external_id  the listing id
 *   starred      "1" | "0"   (optional)
 *   tags         comma-separated labels (optional)
 *
 * These fields are user data and are preserved across re-imports (the scraper
 * upsert never touches them).
 */

use CompanyFinder\Auth;
use CompanyFinder\Database;
use CompanyFinder\ListingRepository;

$config = require __DIR__ . '/../src/bootstrap.php';

(new Auth($config['auth']))->requireApi();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$source     = $_POST['source'] ?? '';
$externalId = $_POST['external_id'] ?? '';
if ($source === '' || $externalId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'source and external_id are required']);
    exit;
}

$starred = array_key_exists('starred', $_POST) ? ($_POST['starred'] === '1' || $_POST['starred'] === 'true') : null;
$tags    = array_key_exists('tags', $_POST) ? (string) $_POST['tags'] : null;

$db   = new Database($config['db_path']);
$repo = new ListingRepository($db->pdo(), $config['anchor']);

$ok = $repo->setUserMeta($source, $externalId, $starred, $tags);
if (!$ok) {
    http_response_code(404);
    echo json_encode(['error' => 'Listing not found or nothing to update']);
    exit;
}

echo json_encode(['saved' => true, 'counts' => $repo->counts()]);

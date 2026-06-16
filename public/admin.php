<?php
/**
 * Admin actions for the dashboard. Currently: clearing the listing database.
 *
 * POST fields:
 *   action  "clear"
 *   scope   "all" (default) | "live" | "samples"
 *
 * Auth-guarded and POST-only so listings can't be wiped by a stray GET.
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

$db   = new Database($config['db_path']);
$repo = new ListingRepository($db->pdo(), $config['anchor']);

$action = $_POST['action'] ?? '';
if ($action !== 'clear') {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$scope = $_POST['scope'] ?? 'all';
$removed = match ($scope) {
    'live'    => $repo->clearLive(),
    'samples' => $repo->clearSamples(),
    'all'     => $repo->clearAll(),
    default   => null,
};

if ($removed === null) {
    http_response_code(400);
    echo json_encode(['error' => "Unknown scope: $scope"]);
    exit;
}

echo json_encode([
    'cleared' => $scope,
    'removed' => $removed,
    'counts'  => $repo->counts(),
]);

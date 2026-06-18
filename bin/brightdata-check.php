<?php
/**
 * Diagnose Bright Data setup for the "Update Now" feature.
 *
 *   php bin/brightdata-check.php
 *
 * Lists the zones your API token can see (with their type) and runs a test
 * fetch through the configured zone. Use it to confirm you are pointing at a
 * Web Unlocker zone — a plain datacenter/residential proxy zone will return
 * the target site's anti-bot "Access Denied" page instead of the real HTML.
 *
 * Run this on a host with outbound internet access (e.g. your IONOS server).
 */

$config = require __DIR__ . '/../src/bootstrap.php';
$bd = $config['brightdata'] ?? [];

if (empty($bd['api_key'])) {
    fwrite(STDERR, "No Bright Data API key configured (set BRIGHTDATA_API_KEY or brightdata.local.php).\n");
    exit(1);
}

$auth = ['Authorization: Bearer ' . $bd['api_key']];

function http_json(string $method, string $url, array $headers, ?string $body = null, int $timeout = 60): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string) $resp, 'err' => $err];
}

echo "1) Active zones for this API token\n";
echo "----------------------------------\n";
$r = http_json('GET', 'https://api.brightdata.com/zone/get_active_zones', $auth);
if ($r['err']) {
    echo "   request error: {$r['err']}\n";
} else {
    $zones = json_decode($r['body'], true);
    if (is_array($zones)) {
        foreach ($zones as $z) {
            $name = $z['name'] ?? '(unknown)';
            $type = $z['type'] ?? ($z['plan']['type'] ?? '(unknown type)');
            $marker = in_array($type, ['unblocker', 'web_unlocker'], true) ? '  <-- Web Unlocker' : '';
            echo "   - {$name}  [type: {$type}]{$marker}\n";
        }
        echo "\n   Use a zone whose type is 'unblocker' as your 'zone' value.\n";
    } else {
        echo "   HTTP {$r['code']}: {$r['body']}\n";
    }
}

echo "\n2) Test fetch through configured zone '" . ($bd['zone'] ?? '') . "'\n";
echo "----------------------------------\n";
$payload = json_encode([
    'zone'    => $bd['zone'] ?? '',
    'url'     => 'https://geo.brightdata.com/welcome.txt?product=unlocker',
    'format'  => 'raw',
    'country' => $bd['country'] ?? 'us',
]);
$r = http_json('POST', $bd['endpoint'] ?? 'https://api.brightdata.com/request', array_merge($auth, ['Content-Type: application/json']), $payload, (int) ($bd['timeout'] ?? 90));
echo "   HTTP {$r['code']}\n";
echo "   " . trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags($r['body'])), 0, 400)) . "\n";

echo "\n3) Test fetch of the BizBuySell search page through that zone\n";
echo "----------------------------------\n";
$payload = json_encode([
    'zone'    => $bd['zone'] ?? '',
    'url'     => $config['sources']['bizbuysell']['search_url'],
    'format'  => 'raw',
    'country' => $bd['country'] ?? 'us',
]);
$r = http_json('POST', $bd['endpoint'] ?? 'https://api.brightdata.com/request', array_merge($auth, ['Content-Type: application/json']), $payload, (int) ($bd['timeout'] ?? 90));
$ok = str_contains($r['body'], 'application/ld+json') || preg_match('/business-(?:opportunity|for-sale)/i', $r['body']);
echo "   HTTP {$r['code']}, " . strlen($r['body']) . " bytes, looks like listings page: " . ($ok ? 'YES' : 'no') . "\n";
if (!$ok) {
    echo "   snippet: " . trim(mb_substr(preg_replace('/\s+/', ' ', strip_tags($r['body'])), 0, 300)) . "\n";
}

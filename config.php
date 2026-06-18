<?php
/**
 * CompanyFinder configuration.
 *
 * Central place for tunable settings: the target search region, the
 * keyword exclusion list, scoring weights, and the anchor location used
 * for the proximity score.
 */

$config = [
    // Human-readable region we are targeting. Used in the UI header and to
    // pick the per-source search URLs below.
    'region' => 'Dallas-Fort Worth Metroplex',

    // Where the SQLite database lives.
    'db_path' => __DIR__ . '/data/companyfinder.sqlite',

    // A listing is flagged NEW if it was first seen within this many days.
    'days_new' => 7,

    // Words that, if present in a listing title or description, cause the
    // listing to be dropped entirely (case-insensitive, whole-ish match).
    'exclude_keywords' => ['restaurant', 'franchise'],

    // The anchor location for the proximity component of the score.
    'anchor' => [
        'label' => 'Plano, Texas',
        'lat'   => 33.0198,
        'lng'   => -96.6989,
    ],

    // Default scoring weights. These can be overridden per-request from the
    // dashboard UI. They are normalized internally, so relative size is what
    // matters, not the absolute values.
    'score_weights' => [
        'price'      => 0.30, // lower asking price scores higher
        'cash_flow'  => 0.40, // higher cash flow scores higher
        'proximity'  => 0.30, // closer to the anchor scores higher
    ],

    // Per-source search entry points for the configured region. The scraper
    // walks these pages (and their pagination) looking for listing data.
    // `page_url` is the template for pages 2+ ({page} is the page number);
    // page 1 uses `search_url`.
    'sources' => [
        'bizbuysell' => [
            'label'      => 'BizBuySell',
            'base'       => 'https://www.bizbuysell.com',
            'search_url' => 'https://www.bizbuysell.com/texas/dallas-fort-worth-metroplex-businesses-for-sale/',
            'page_url'   => 'https://www.bizbuysell.com/texas/dallas-fort-worth-metroplex-businesses-for-sale/{page}/',
            'max_pages'  => 10,
        ],
        'bizquest' => [
            'label'      => 'BizQuest',
            'base'       => 'https://www.bizquest.com',
            'search_url' => 'https://www.bizquest.com/businesses-for-sale-in-dallas-fort-worth-tx/',
            'page_url'   => 'https://www.bizquest.com/businesses-for-sale-in-dallas-fort-worth-tx/{page}/',
            'max_pages'  => 10,
        ],
    ],

    // Keep only listings that are actually in the metroplex. Listings whose
    // resolved location is in another state, or further than max_radius_mi from
    // the anchor, are dropped. Listings with an unrecognized location are kept
    // (the search URL is already region-scoped).
    'region_filter' => [
        'enabled'       => true,
        'max_radius_mi' => 75,
        'state'         => 'TX',
    ],

    // Polite scraping defaults.
    'http' => [
        'user_agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'timeout'         => 30,
        'delay_seconds'   => 2,   // pause between page requests

        // Optional fetch proxy / scraping API.
        //
        // BizBuySell and BizQuest sit behind anti-bot protection that returns
        // HTTP 403 to plain server-side requests, and IONOS shared hosting
        // cannot run a headless browser to get around it. The reliable fix on
        // shared hosting is to route the fetch through a rendering proxy /
        // scraping API (ScraperAPI, ScrapingBee, ZenRows, Bright Data, ...).
        //
        // Set this to that service's endpoint template, using {url} as the
        // placeholder for the (URL-encoded) target page. Examples:
        //   ScraperAPI: 'https://api.scraperapi.com/?api_key=YOUR_KEY&render=true&url={url}'
        //   ScrapingBee:'https://app.scrapingbee.com/api/v1/?api_key=YOUR_KEY&render_js=true&url={url}'
        //   ZenRows:    'https://api.zenrows.com/v1/?apikey=YOUR_KEY&js_render=true&url={url}'
        // Leave null to fetch directly (works for sites without bot walls).
        'proxy_template'  => null,
    ],

    // Login gate for the dashboard and write endpoints.
    //
    // The default credentials are username "admin" / password "companyfinder".
    // CHANGE THEM before exposing the app: run `php bin/set-password.php` to
    // write an auth.local.php override (kept out of git) with your own
    // username and a fresh password hash.
    'auth' => [
        'enabled'       => true,
        'username'      => 'admin',
        'password_hash' => '$2y$12$I1w/9.wL9kpTRDr/MZH.cevAxWqMYRUvxoxFZCkxjO6NTuTO3sT2a', // "companyfinder"
        'session_name'  => 'companyfinder_session',
    ],

    // Bright Data Web Unlocker API, used by the dashboard's "Update Now" button
    // and `php bin/scrape.php --brightdata` to fetch the source pages through a
    // service that clears the sites' anti-bot 403s.
    //
    // The API key is a secret: do NOT put it here (this file is committed).
    // Provide it via the BRIGHTDATA_API_KEY environment variable, or a
    // gitignored brightdata.local.php override (see brightdata.local.php.example).
    'brightdata' => [
        'enabled'  => (bool) getenv('BRIGHTDATA_API_KEY'),
        'api_key'  => getenv('BRIGHTDATA_API_KEY') ?: '',
        'zone'     => getenv('BRIGHTDATA_ZONE') ?: 'web_unlocker1',
        'country'  => getenv('BRIGHTDATA_COUNTRY') ?: 'us',
        'endpoint' => 'https://api.brightdata.com/request',
        'timeout'  => 90,
    ],
];

// Optional local secrets overrides (gitignored). Lets you set real credentials
// without editing this tracked file.
foreach (['auth' => '/auth.local.php', 'brightdata' => '/brightdata.local.php'] as $key => $file) {
    $path = __DIR__ . $file;
    if (is_file($path)) {
        $config[$key] = array_merge($config[$key], require $path);
    }
}

return $config;

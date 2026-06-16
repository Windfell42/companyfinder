<?php
/**
 * CompanyFinder configuration.
 *
 * Central place for tunable settings: the target search region, the
 * keyword exclusion list, scoring weights, and the anchor location used
 * for the proximity score.
 */

return [
    // Human-readable region we are targeting. Used in the UI header and to
    // pick the per-source search URLs below.
    'region' => 'Dallas-Fort Worth Metroplex',

    // Where the SQLite database lives.
    'db_path' => __DIR__ . '/data/companyfinder.sqlite',

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
    'sources' => [
        'bizbuysell' => [
            'label'     => 'BizBuySell',
            'base'      => 'https://www.bizbuysell.com',
            'search_url' => 'https://www.bizbuysell.com/dallas-fort-worth-metro-area-businesses-for-sale/',
            'max_pages' => 10,
        ],
        'bizquest' => [
            'label'      => 'BizQuest',
            'base'       => 'https://www.bizquest.com',
            'search_url' => 'https://www.bizquest.com/businesses-for-sale-in-dallas-fort-worth-tx/',
            'max_pages'  => 10,
        ],
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
];

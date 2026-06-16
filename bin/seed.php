<?php
/**
 * Seed the database with realistic sample DFW listings so the dashboard is
 * fully demonstrable without a successful live scrape. Every row is flagged
 * is_sample = 1 so it can be cleared with `php bin/scrape.php --clear-samples`.
 *
 * The sample set deliberately contains no "Restaurant" or "franchise"
 * listings, matching the configured exclusion rules.
 *
 *   php bin/seed.php
 */

use CompanyFinder\Database;
use CompanyFinder\Geo;
use CompanyFinder\ListingRepository;

$config = require __DIR__ . '/../src/bootstrap.php';

$db   = new Database($config['db_path']);
$repo = new ListingRepository($db->pdo(), $config['anchor']);

/** title, type, location, price, cash flow, gross revenue, source */
$rows = [
    ['Established HVAC Service & Repair Company',        'Home Services',        'Plano, TX',        1250000, 410000, 2300000, 'bizbuysell'],
    ['Profitable Commercial Landscaping Business',       'Landscaping',          'Frisco, TX',        890000,  265000, 1600000, 'bizbuysell'],
    ['Auto Repair Shop with Real Estate',                'Automotive',           'Richardson, TX',    675000,  198000, 980000,  'bizbuysell'],
    ['Boutique Digital Marketing Agency',                'Marketing & Media',    'Dallas, TX',        540000,  220000, 760000,  'bizquest'],
    ['Niche E-Commerce Brand (Outdoor Goods)',           'E-Commerce',           'Allen, TX',         425000,  155000, 1100000, 'bizquest'],
    ['Commercial Cleaning & Janitorial Service',         'Cleaning Services',    'Garland, TX',       310000,  120000, 720000,  'bizbuysell'],
    ['Pet Grooming & Boarding Facility',                 'Pet Services',         'McKinney, TX',      295000,  98000,  410000,  'bizbuysell'],
    ['Established Plumbing Contractor',                  'Home Services',        'Carrollton, TX',    980000,  340000, 1850000, 'bizquest'],
    ['Specialty Coffee Roaster & Wholesaler',            'Food & Beverage',      'Dallas, TX',        720000,  185000, 1300000, 'bizbuysell'],
    ['Profitable Daycare & Early Learning Center',       'Education & Childcare','Plano, TX',         1450000, 380000, 1900000, 'bizquest'],
    ['Managed IT Services Provider (MSP)',               'IT & Technology',      'Frisco, TX',        2100000, 620000, 3200000, 'bizbuysell'],
    ['Sign Manufacturing & Installation Company',        'Manufacturing',        'Irving, TX',        1150000, 295000, 2400000, 'bizquest'],
    ['Med Spa & Aesthetics Clinic',                      'Health & Beauty',      'Southlake, TX',     1380000, 445000, 1750000, 'bizbuysell'],
    ['Liquor Store with High Foot Traffic',              'Retail',               'Arlington, TX',     890000,  210000, 3100000, 'bizbuysell'],
    ['CNC Machine Shop (Aerospace Clients)',             'Manufacturing',        'Fort Worth, TX',    2650000, 710000, 4200000, 'bizquest'],
    ['Mobile Auto Detailing Business',                   'Automotive',           'Mesquite, TX',      145000,  72000,  240000,  'bizbuysell'],
    ['Established Insurance Agency Book of Business',     'Financial Services',   'Plano, TX',         620000,  240000, 540000,  'bizquest'],
    ['Wholesale Bakery & Distribution',                  'Food & Beverage',      'Grand Prairie, TX', 1050000, 280000, 2100000, 'bizbuysell'],
    ['Self-Storage Facility (Stabilized)',               'Real Estate',          'Denton, TX',        3400000, 410000, 720000,  'bizquest'],
    ['Pool Service & Maintenance Route',                 'Home Services',        'Lewisville, TX',    275000,  130000, 360000,  'bizbuysell'],
    ['B2B SaaS for Logistics (Bootstrapped)',            'IT & Technology',      'Dallas, TX',        1900000, 540000, 1200000, 'bizquest'],
    ['Children\'s Indoor Play & Party Center',           'Entertainment',        'Frisco, TX',        680000,  175000, 940000,  'bizbuysell'],
    ['Electrical Contracting Company',                   'Home Services',        'Mansfield, TX',     1320000, 395000, 2600000, 'bizquest'],
    ['Vending Machine Route (Healthy Snacks)',           'Distribution',         'Carrollton, TX',    185000,  88000,  310000,  'bizbuysell'],
    ['Dry Cleaning Plant with Drop Stores',              'Personal Services',    'Richardson, TX',    540000,  165000, 820000,  'bizquest'],
    ['Custom Cabinet & Millwork Shop',                   'Manufacturing',        'Fort Worth, TX',    760000,  205000, 1400000, 'bizbuysell'],
    ['Established Tax & Bookkeeping Practice',           'Financial Services',   'Plano, TX',         480000,  195000, 610000,  'bizquest'],
    ['Fitness Studio (Members on Recurring Billing)',    'Health & Fitness',     'Allen, TX',         320000,  110000, 480000,  'bizbuysell'],
    ['Commercial Printing & Promotional Products',       'Printing',             'Garland, TX',       590000,  150000, 1250000, 'bizquest'],
    ['Roofing & Exterior Contractor',                    'Home Services',        'Rowlett, TX',       1080000, 360000, 2900000, 'bizbuysell'],
];

$now = date('c');
$listings = [];
foreach ($rows as $i => $r) {
    [$title, $type, $location, $price, $cashFlow, $gross, $source] = $r;
    $coords = Geo::coordsFor($location);
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
    $extId = sprintf('SAMPLE-%03d', $i + 1);
    $base = $config['sources'][$source]['base'];
    $listings[] = [
        'source'        => $source,
        'external_id'   => $extId,
        'title'         => $title,
        // Sample rows link to the live search page for the region so the
        // "open listing" action still goes somewhere real and relevant.
        'url'           => $config['sources'][$source]['search_url'],
        'description'   => "Sample listing for demonstration. $type business located in $location within the DFW Metroplex.",
        'business_type' => $type,
        'location'      => $location,
        'state'         => 'TX',
        'price'         => $price,
        'cash_flow'     => $cashFlow,
        'gross_revenue' => $gross,
        'latitude'      => $coords[0] ?? null,
        'longitude'     => $coords[1] ?? null,
        'distance_mi'   => null,
        'is_sample'     => 1,
        'scraped_at'    => $now,
    ];
}

$n = $repo->upsertMany($listings);
fwrite(STDOUT, "Seeded $n sample listings.\n");

// Make the change-tracking demo realistic: backdate most rows so they look
// "established", leave the last few first-seen today (they show as NEW), and
// simulate a price drop on a couple so price-change indicators appear.
$pdo = $db->pdo();
$old = date('c', strtotime('-30 days'));

// Backdate everything except the final 5 sample rows.
$pdo->exec("UPDATE listings SET first_seen = '$old', last_seen = '$old'
            WHERE is_sample = 1 AND external_id NOT IN
            (SELECT external_id FROM listings WHERE is_sample = 1 ORDER BY external_id DESC LIMIT 5)");

// Simulate price reductions on two established listings by re-importing them
// with a lower price (this exercises the real change-tracking path).
$reduce = $pdo->query("SELECT source, external_id, title, url, description, business_type,
                              location, price, cash_flow, gross_revenue, latitude, longitude
                       FROM listings WHERE is_sample = 1 AND first_seen = '$old'
                       ORDER BY price DESC LIMIT 2")->fetchAll();
foreach ($reduce as $r) {
    $r['price']      = round($r['price'] * 0.88); // ~12% price cut
    $r['is_sample']  = 1;
    $r['scraped_at'] = $now;
    $repo->upsertMany([$r]);
}
fwrite(STDOUT, "Backdated history and simulated " . count($reduce) . " price reductions for the demo.\n");

<?php
$config = require __DIR__ . '/../src/bootstrap.php';
$region = htmlspecialchars($config['region']);
$anchor = htmlspecialchars($config['anchor']['label']);
$exclude = htmlspecialchars(implode(', ', $config['exclude_keywords']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CompanyFinder — <?= $region ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="app-header">
    <div>
        <h1>CompanyFinder</h1>
        <p class="sub">Business-for-sale intelligence · <strong><?= $region ?></strong> · scored against <strong><?= $anchor ?></strong></p>
    </div>
    <div class="meta" id="meta"></div>
</header>

<main>
    <aside class="filters">
        <h2>Filters</h2>

        <label>Source
            <select id="f-source">
                <option value="">All sources</option>
                <option value="bizbuysell">BizBuySell</option>
                <option value="bizquest">BizQuest</option>
            </select>
        </label>

        <label>Business type
            <select id="f-type"><option value="">All types</option></select>
        </label>

        <label>Contains keywords <span class="hint">(comma separated — all must match)</span>
            <input type="text" id="f-contains" placeholder="e.g. recurring, service">
        </label>

        <label>Does not contain <span class="hint">(comma separated)</span>
            <input type="text" id="f-not-contains" placeholder="e.g. gas station, liquor">
        </label>

        <div class="row">
            <label>Min price <input type="number" id="f-min-price" placeholder="0"></label>
            <label>Max price <input type="number" id="f-max-price" placeholder="any"></label>
        </div>
        <label>Min cash flow <input type="number" id="f-min-cf" placeholder="0"></label>

        <fieldset class="weights">
            <legend>Scoring weights</legend>
            <label>Price (lower is better) <input type="range" id="w-price" min="0" max="100" value="30"><span class="wv" id="wv-price">30</span></label>
            <label>Cash flow (higher is better) <input type="range" id="w-cf" min="0" max="100" value="40"><span class="wv" id="wv-cf">40</span></label>
            <label>Proximity to <?= $anchor ?> <input type="range" id="w-prox" min="0" max="100" value="30"><span class="wv" id="wv-prox">30</span></label>
        </fieldset>

        <button id="apply" class="primary">Apply filters</button>
        <button id="reset" class="ghost">Reset</button>

        <p class="note">Listings containing <strong><?= $exclude ?></strong> are excluded at scrape time.</p>
    </aside>

    <section class="content">
        <div class="charts">
            <div class="card">
                <h3>Trend by business type</h3>
                <div id="chart-type" class="barchart"></div>
            </div>
            <div class="card">
                <h3>Price distribution</h3>
                <div id="chart-price" class="barchart"></div>
            </div>
        </div>

        <div class="card">
            <div class="table-head">
                <h3>Opportunities <span id="count" class="count"></span></h3>
                <div class="sort">
                    Sort:
                    <select id="sort">
                        <option value="score">Score</option>
                        <option value="price">Price</option>
                        <option value="cash_flow">Cash flow</option>
                        <option value="distance_mi">Distance to anchor</option>
                    </select>
                </div>
            </div>
            <div id="results"></div>
        </div>
    </section>
</main>

<script src="assets/app.js"></script>
</body>
</html>

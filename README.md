# CompanyFinder

A PHP web app that scrapes business-for-sale listings from **BizBuySell** and
**BizQuest** for the **Dallas–Fort Worth Metroplex**, scores them as
acquisition opportunities, and lets you explore trends and filter the results.

## Features

- **Scraping** of `bizbuysell.com` and `bizquest.com` for the DFW Metroplex,
  with multiple extraction strategies (JSON-LD structured data, inline JSON
  app state, and DOM fallback).
- **Keyword exclusion** — listings containing `Restaurant` or `franchise`
  (configurable) are dropped at scrape time.
- **Opportunity scoring (0–100)** combining three weighted, normalized factors:
  - **Price** — lower asking price scores higher
  - **Cash flow** — higher cash flow scores higher
  - **Proximity** — closer to **Plano, Texas** scores higher
  The weights are adjustable live from the dashboard.
- **Business type inference** — sources like BizBuySell don't include a
  category, so a keyword classifier derives one (Automotive, Home Services,
  Manufacturing, Health & Medical, …) from the title/description.
- **Interesting tagging** — star a listing as interesting and add free-text
  tags. This metadata is preserved across re-imports, is searchable, and has
  an **Interesting only** filter.
- **Change tracking** — every import records when a listing was first/last
  seen and writes a price-history entry on first sight and on any price change.
  Listings first seen within the last 7 days (configurable via `days_new`) are
  flagged **NEW**; price moves show an inline ▲/▼ indicator with the old price,
  delta and %, and a "price history" expander per listing. Filter to **new
  only** or **price-changed only**, and sort by **newest first**.
- **Trends** — bar charts of listing count + average price by business type,
  and a price-distribution histogram. Both react to the active filters.
- **Arbitrary keyword filtering** — `contains` (all terms must appear) and
  `does not contain` (none may appear), across title, description, type and
  location.
- **Direct outbound links** to every listing on the source site.

## Requirements

- PHP 8.1+ with `pdo_sqlite`, `curl`, `dom`, and `mbstring` (all standard).
- No third-party PHP packages — a small built-in autoloader is used.

## Quick start

```bash
# 1. Seed demonstration data so the dashboard is populated immediately
php bin/seed.php

# 2. (Optional) Run a live scrape — requires outbound internet access
php bin/scrape.php

# 3. Serve the app
php -S localhost:8000 -t public
# open http://localhost:8000 and sign in (default: admin / companyfinder)
```

## Login

The dashboard and its data endpoints (`api.php`, `import.php`) sit behind a
simple session login, enabled by default.

- **Default credentials:** username `admin`, password `companyfinder`.
- **Change them before exposing the app.** Run the helper, which writes a
  gitignored `auth.local.php` override (your real password is hashed and never
  committed):

  ```bash
  php bin/set-password.php              # prompts for username + password
  php bin/set-password.php steven s3cret  # non-interactive
  ```

- To turn the gate off entirely (e.g. behind a VPN), set `auth.enabled` to
  `false` in `config.php`.

Sessions use PHP's native cookies; "Sign out" is in the dashboard header.
When a session expires, API calls return `401` and the UI bounces to the login
page. For an internet-facing deployment, also serve over HTTPS.

## Scraping

```bash
php bin/scrape.php                    # all sources
php bin/scrape.php --source=bizquest  # one source
php bin/scrape.php --clear-samples    # remove seeded sample rows first
php bin/scrape.php --clear-all        # wipe the whole database first
```

You can also clear the database from the dashboard: open the **Import listings
from saved HTML** panel and use the **Clear database** controls (everything,
imported-only, or sample-only).

Live scraping needs outbound access to `bizbuysell.com` and `bizquest.com`.
If that access is blocked, or the sites change their markup, the scraper logs
the failure and writes nothing — the dashboard keeps serving whatever is
already stored (including the seeded sample data). Sample rows are clearly
flagged with a **SAMPLE** badge in the UI and counted separately in the header.

> Be a good citizen: scraping may be subject to each site's Terms of Service
> and `robots.txt`. The scraper sends a realistic User-Agent and pauses between
> page requests, but you are responsible for using it within the sites' terms.

## Parsing options on IONOS shared hosting

It helps to split "scraping" into two steps:

- **Parsing** (HTML → structured listings) is pure PHP — `DOMDocument` /
  `DOMXPath`, JSON-LD decoding, and regex — and runs fine on IONOS shared
  hosting with no extra packages.
- **Fetching** is the hard part: BizBuySell and BizQuest sit behind anti-bot
  protection that returns **HTTP 403** to plain server-side requests, and
  shared hosting **cannot run a headless browser** (no Chrome/Puppeteer, no
  root) to render past it.

Given that, there are three workable approaches, in order of reliability on
shared hosting:

1. **Offline HTML import (most reliable, no extra cost).** Open the search
   page in your own browser (which clears the bot wall for you), use
   *Save Page As → Web Page, HTML Only*, upload the file, and parse it
   server-side:

   ```bash
   php bin/import.php saved/bizbuysell-dfw-page1.html --source=bizbuysell
   php bin/import.php saved/ --source=bizquest        # whole folder of *.html
   ```

   Same parsing, exclusion (Restaurant/franchise), geocoding and scoring as the
   live scraper — just with the fetch done by a real browser.

   No shell or FTP? Use the **Import listings from saved HTML** panel at the top
   of the dashboard to upload the file(s) or paste the page source directly in
   the browser (handled by `public/import.php`).

2. **Rendering proxy / scraping API (best for automation).** Services like
   ScraperAPI, ScrapingBee, ZenRows or Bright Data fetch and render the page
   for you and return clean HTML; you call them with ordinary PHP cURL, which
   shared hosting allows. Set `http.proxy_template` in `config.php` to the
   service endpoint (use `{url}` as the target placeholder) and run
   `php bin/scrape.php` as normal — every fetch is routed through the proxy.

3. **Direct fetch (works only without a bot wall).** Leave `proxy_template`
   null. This is what `bin/scrape.php` does by default; it succeeds for sites
   that don't block bots but will hit 403 on these two.

For unattended refreshes, point an IONOS **cron job** at `bin/scrape.php`
(option 2) — e.g. `php /path/to/bin/scrape.php` once a day. The dashboard reads
straight from the SQLite database, so it never blocks on a scrape.

### PHP parser libraries (all shared-hosting friendly)

The built-in `DOMDocument`/`DOMXPath` covers everything here with zero
dependencies. If you prefer a richer API, these are pure-PHP and upload-via-FTP
friendly (no native extensions): **Symfony DomCrawler + CssSelector** (CSS/XPath
selectors), **PHP Simple HTML DOM Parser**, and **Masterminds/HTML5** (better
HTML5 handling). They replace the *parsing* layer only — none of them solve the
403, so you still need option 1 or 2 to obtain the HTML.

## Configuration

Everything tunable lives in [`config.php`](config.php):

| Setting             | Purpose                                            |
| ------------------- | -------------------------------------------------- |
| `region`            | Region label shown in the UI                       |
| `exclude_keywords`  | Words that disqualify a listing                    |
| `anchor`            | Location used for the proximity score (Plano, TX)  |
| `score_weights`     | Default price / cash-flow / proximity weights      |
| `sources`           | Per-site search URLs and pagination depth          |
| `http.proxy_template` | Rendering-proxy / scraping-API endpoint (`{url}`) |
| `auth`              | Login gate: enabled flag, username, password hash  |

## Project layout

```
config.php              app configuration
bin/scrape.php          CLI live scraper (direct or via proxy)
bin/import.php          CLI offline importer for browser-saved HTML
bin/seed.php            CLI sample-data seeder
bin/set-password.php    CLI to set dashboard login credentials
src/Auth.php            session login guard
src/Database.php        SQLite connection + schema
src/Scraper.php         multi-strategy listing scraper
src/ListingRepository.php  storage, filtering, trend aggregation
src/Scoring.php         opportunity scoring engine
src/Geo.php             DFW city coordinates + distance math
public/index.php        dashboard
public/api.php          JSON API (search + meta)
public/assets/          CSS + vanilla-JS front end
data/                   SQLite database (gitignored)
```

## How scoring works

For the current result set, each factor is min–max normalized across the
listings being compared, then inverted where "lower is better" (price,
distance). The three normalized factors are combined using the (normalized)
weights and scaled to 0–100. Because normalization is relative to the visible
set, the score answers "how good is this opportunity *compared to the others
shown*", and re-weighting instantly re-ranks the list.

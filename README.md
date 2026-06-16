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
# open http://localhost:8000
```

## Scraping

```bash
php bin/scrape.php                    # all sources
php bin/scrape.php --source=bizquest  # one source
php bin/scrape.php --clear-samples    # remove seeded sample rows first
```

Live scraping needs outbound access to `bizbuysell.com` and `bizquest.com`.
If that access is blocked, or the sites change their markup, the scraper logs
the failure and writes nothing — the dashboard keeps serving whatever is
already stored (including the seeded sample data). Sample rows are clearly
flagged with a **SAMPLE** badge in the UI and counted separately in the header.

> Be a good citizen: scraping may be subject to each site's Terms of Service
> and `robots.txt`. The scraper sends a realistic User-Agent and pauses between
> page requests, but you are responsible for using it within the sites' terms.

## Configuration

Everything tunable lives in [`config.php`](config.php):

| Setting             | Purpose                                            |
| ------------------- | -------------------------------------------------- |
| `region`            | Region label shown in the UI                       |
| `exclude_keywords`  | Words that disqualify a listing                    |
| `anchor`            | Location used for the proximity score (Plano, TX)  |
| `score_weights`     | Default price / cash-flow / proximity weights      |
| `sources`           | Per-site search URLs and pagination depth          |

## Project layout

```
config.php              app configuration
bin/scrape.php          CLI live scraper
bin/seed.php            CLI sample-data seeder
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

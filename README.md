# DataHelm Crawler

Scrapy-style web crawler for Laravel — auto-detects lists, pagination, and fields;
supports API/SPA sites, infinite scroll, image downloading, dedup, and pluggable output
sinks.

## Installation

```bash
composer require datahelm/crawler
```

Publish config (optional):

```bash
php artisan vendor:publish --tag=crawler-config
```

## Quick start

```bash
php artisan datahelm:scrap:generate "https://example.com/listing" --get-detail=true --robot
php artisan datahelm:robot:example --limit=10
```

## HTTP transports

The package works out of the box with **plain HTTP** (`guzzle`). Heavier transports are
**optional** — only needed for JS-heavy sites or bot protection.

| Transport | What it does | Extra infrastructure |
|-----------|--------------|----------------------|
| `guzzle` | Plain HTTP (**default**) | None |
| `auto` | Escalates on bot blocks | browserless and/or FlareSolverr recommended |
| `browser` | Headless Chrome (JS / SPA) | [browserless](https://github.com/browserless/chrome) |
| `flaresolverr` | Cloudflare challenge solver | [FlareSolverr](https://github.com/FlareSolverr/FlareSolverr) |
| `scraping_api` | Managed anti-bot API | Paid API key |

Escalation ladder when using `auto`:

```
guzzle ─► browser ─► flaresolverr ─► scraping_api
```

## Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `CRAWLER_TRANSPORT` | `guzzle` | `guzzle`, `browser`, `flaresolverr`, `scraping_api`, `auto` |
| `CRAWLER_COMMAND_PREFIX` | `datahelm` | Artisan command prefix (`datahelm:scrap:generate`, …) |
| `BROWSERLESS_URL` | `http://browserless:3000` | browserless service URL |
| `BROWSERLESS_TOKEN` | *(empty)* | Optional browserless auth token |
| `FLARESOLVERR_URL` | `http://flaresolverr:8191` | FlareSolverr service URL |
| `FLARESOLVERR_MAX_TIMEOUT` | `60000` | Challenge timeout (ms) |
| `CRAWLER_PROXY_URL` | *(empty)* | Upstream proxy for browser / flaresolverr transports |
| `SCRAPING_API_URL` | *(empty)* | Managed scraping API base URL |
| `SCRAPING_API_KEY` | *(empty)* | API key for `scraping_api` transport |

When Laravel runs **inside Docker** on the same network as the services, use hostnames
`browserless` and `flaresolverr`. When Laravel runs on the **host machine**, use
`http://localhost:3010` and `http://localhost:8191`.

## Optional: anti-bot services only

Start browserless and FlareSolverr without a full development stack:

```bash
docker compose -f docker/compose.services.yml up -d
```

Stop when done (each service runs a full Chromium and uses RAM/CPU):

```bash
docker compose -f docker/compose.services.yml stop
```

## Full development environment

For nginx, PHP, PostgreSQL, Redis, Supervisor, and all crawler services together, use the
separate environment repository:

**[github.com/datahelm/environment](https://github.com/datahelm/environment)**

```bash
git clone https://github.com/datahelm/environment.git
cd environment
cp .env.example .env
export UID=$(id -u) GID=$(id -g)
docker compose up -d
```

## Artisan commands

| Command | Description |
|---------|-------------|
| `datahelm:scrap:generate` | Auto-detect a site and generate a scrape blueprint |
| `datahelm:scrap:run` | Run a blueprint and export items as JSON |
| `datahelm:scrap:shell` | Interactive CSS/XPath selector shell against a live URL |
| `datahelm:scrap:validate` | Validate a blueprint JSON file |
| `datahelm:robot:{name}` | Run a site-specific robot (scaffolded with `--robot`) |

## License

MIT

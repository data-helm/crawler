# DataHelm Crawler

Scrapy-style web crawler for Laravel — auto-detects lists, pagination, and fields;
supports API/SPA sites, infinite scroll, image downloading, dedup, pluggable output
sinks, and **LLM-ready Markdown output** (like Firecrawl / Crawl4AI).

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
php artisan datahelm:scrap:generate "https://example.com/listing" --get-detail=true --robot-name=Example
php artisan datahelm:robot:example --limit=10
```

Scaffolding a `Robot{Name}` command is the default (pass `--blueprint` instead to save a
reusable JSON file for `datahelm:scrap:run` rather than a robot).

## Single-page mode

Not every URL is a list. An article, a company profile, a one-off dashboard page — these
are **one record**, not a repeating item. `datahelm:scrap:generate` normally requires a
repeating pattern to detect (it will error with "Could not detect a repeating item list"
on a page that has none); `--single-page` skips that requirement entirely:

```bash
php artisan datahelm:scrap:generate "https://example.com/about-us" --single-page --robot-name=AboutUs
```

This treats the whole page as a single item: field detectors (title, price, image,
description, …) run directly against `<body>` instead of a detected list-item sample, and
the resulting blueprint is exactly what you'd expect — `item_selector: "body"` with
pagination disabled:

```json
{
  "item_selector": "body",
  "pagination": { "strategy": "none", "css": "" }
}
```

No engine changes are needed to run it: `CrawlEngine` already treats any `item_selector`
that matches exactly one node as a one-item crawl, so `--single-page` is purely a
generation-time shortcut. `--search-filters` still works normally alongside it — each
filter URL is fetched and treated as its own single-page item, useful for scraping the
same kind of one-off page (e.g. a profile) across several known URLs.

**`--main-content`** (Firecrawl's `onlyMainContent`) — scope detection to the page's
primary content region so nav links, footer text and sidebars never become fields.
The detector looks for `<main>` / `[role=main]` / `#content`-style containers and bakes
that region in as the item selector (e.g. `item_selector: "main#content"` on Wikipedia);
when no region is confidently found — or its selector isn't unique on the page — it
falls back to `<body>` and says so:

```bash
php artisan datahelm:scrap:generate "https://en.wikipedia.org/wiki/Web_scraping" \
  --single-page --main-content --robot-name=WikiArticle
```

**How other tools handle this:** Scrapy has no dedicated concept either — you just write a
`parse()` that reads fields off `response` directly and yields one item, instead of looping
over a selector list (the same idea as `item_selector: "body"`). Firecrawl draws the line
explicitly with two endpoints: `/scrape` (one URL → one result) versus `/crawl` (discovers
and follows links into many results) — `--single-page` is this package's equivalent of
`/scrape`.

## Presets (field-detection heuristics)

A preset is a named bundle of heuristics used **only** by `datahelm:scrap:generate` —
the auto-detection step that guesses selectors on a site it has never seen. It has
no effect once a blueprint is saved; it only shapes what gets written into that
blueprint the moment it's generated.

```bash
php artisan datahelm:scrap:generate "https://example.com/listing" --preset=ecommerce
```

Built-in presets (`config/crawler.php` → `presets`), selected with `--preset=` or the
`CRAWLER_PRESET` env var (default: `generic`):

| Preset | Use for |
|--------|---------|
| `generic` | Unsure / mixed content — safe default for any country, any vertical |
| `ecommerce` | Online shops, marketplaces (adds `handle`, `product-image`, `qty`, …) |
| `auctions` | Auction/lot listings |
| `properties` | Real-estate listings |

Each preset is an array of hints the detectors match against CSS classes, HTML
attributes, and JSON field names:

| Key | Controls |
|-----|----------|
| `price_patterns` | Regexes for currency formats (`$`, `R$`, `€`, `£`, …) — **locale-specific**, add your own symbol if missing |
| `image_field_hints` | CSS class / JSON key fragments that mark an image field (`image`, `thumb`, `gallery`, …) |
| `link_field_hints` | Same, for the item's URL/link field (`url`, `href`, `slug`, `handle`, …) |
| `rating_hints` | CSS class fragments for star/score widgets |
| `stock_hints` | CSS class fragments for availability/inventory |
| `sku_hints` | CSS class / JSON key fragments for product codes (SKU, EAN, MPN, …) |
| `image_path_prefix` | A fixed URL path segment that identifies image URLs on a known platform (e.g. VTEX's `/arquivos/`); `null` = auto |
| `list_core_fields`, `list_min_core_fields`, `list_min_success_rate`, `list_min_link_uniqueness` | Thresholds the detector uses to decide "this repeating block is really a list of items" |
| `item_schema` | Suggested `item_schema` (type-coercion map) to carry into the generated blueprint |

`image_field_hints` / `link_field_hints` / `rating_hints` / `stock_hints` / `sku_hints`
are CSS-class vocabulary and stay in English regardless of the page's display
language (developers write `class="star-rating"` on French/Portuguese/Arabic sites
alike). Only `price_patterns` and `image_path_prefix` are actually locale/platform
specific.

**Adding your own preset** — extend an existing one by merging in local vocabulary,
in `config/crawler.php`:

```php
'presets' => [
    // ...
    'auctions_pt_BR' => [
        'price_patterns'    => ['/R\$\s*[\d.,]+/'],
        'image_field_hints' => array_merge(
            ['image', 'img', 'photo', 'thumb'],
            ['foto', 'fotos', 'imagem', 'imagens', 'galeria'],
        ),
        'link_field_hints'  => ['url', 'link', 'href', 'permalink', 'lote'],
        'image_path_prefix' => '/arquivos/',
    ],
],
```

```bash
php artisan datahelm:scrap:generate <url> --preset=auctions_pt_BR
```

## Item pipeline

Where a preset shapes *how fields are found*, the pipeline shapes *what happens to
their values afterwards* — it runs on every crawl execution (`datahelm:scrap:run`),
not just generation, transforming each already-extracted `ScrapedItem` before it's
exported.

By default (`config/crawler.php` → `pipeline`) every item passes through:

1. **`TrimProcessor`** — collapses whitespace and trims every string field.
2. **`AbsoluteUrlProcessor`** — resolves relative `link`/`image`/`gallery_images`/…
   URLs against the page they were scraped from.

A blueprint can override this default for itself with `pipeline_names` — a list of
short names resolved against `config('crawler.pipeline_registry')`:

```php
'pipeline_registry' => [
    'trim'            => TrimProcessor::class,
    'absolute_url'    => AbsoluteUrlProcessor::class,
    'schema_coercion' => SchemaCoercionProcessor::class,
],
```

```json
{ "pipeline_names": ["trim", "schema_coercion"] }
```

This is a **replacement, not an addition**: listing `["trim"]` runs only
`TrimProcessor` for that blueprint — `AbsoluteUrlProcessor` no longer runs, so
relative URLs are left as-is. Leaving `pipeline_names` empty (`[]`, the default)
keeps the global pipeline untouched — most blueprints never need to set this.

**Adding a custom processor** — implement `ItemProcessor`, register it, then
reference it by name:

```php
final class StripEmojiProcessor implements \DataHelm\Crawler\Pipeline\ItemProcessor
{
    public function process(ScrapedItem $item, string $pageUrl): ScrapedItem
    {
        $title = $item->get('title');
        if (is_string($title)) {
            $item->set('title', preg_replace('/[\x{1F300}-\x{1FAFF}]/u', '', $title));
        }

        return $item;
    }
}
```

```php
// config/crawler.php
'pipeline_registry' => [
    // ...
    'strip_emoji' => \App\Pipeline\StripEmojiProcessor::class,
],
```

```json
{ "pipeline_names": ["trim", "absolute_url", "strip_emoji"] }
```

### Presets vs. pipeline

They sound similar (both pick a named config by string) but act at opposite ends
of the process:

| | Presets | Pipeline |
|---|---|---|
| Runs during | `scrap:generate` only, once | `scrap:run`, every execution |
| Acts on | Detection heuristics — **finding** the right selectors | Extracted values — **transforming** them after extraction |
| Selected via | `--preset=ecommerce` on the CLI | `"pipeline_names": [...]` in the blueprint JSON |
| Outlives the run? | No — only its effect on the generated blueprint persists | Yes — read from the blueprint on every future run |

## Markdown / LLM-ready output

Turn any page into clean Markdown instead of a wall of HTML — the feature Firecrawl
and Crawl4AI are known for, now in the Laravel world. Two ways to use it:

**1. As a field** — render one element's content (an article body, a product
description) as Markdown by setting the field `type` to `markdown`. The `css` selector
locates the element; its content is converted to Markdown (headings, lists, links,
images, code, tables preserved; scripts, styles, and site chrome stripped):

```json
{
  "name": "description",
  "css": ".product-description",
  "type": "markdown"
}
```

**2. As an output format** — export a whole crawl as a single Markdown document, one
section per item, ready to drop into an LLM context window or a RAG index. Set the
blueprint's `output.format`:

```json
{ "output": { "format": "markdown" } }
```

```bash
php artisan datahelm:scrap:run example --output=storage/app/scrapes/example.md
```

The converter (`DataHelm\Crawler\Markdown\HtmlToMarkdown`) is dependency-free (ext-dom
only) and can be used on its own:

```php
$markdown = (new \DataHelm\Crawler\Markdown\HtmlToMarkdown())->convert($html);
```

## Output formats

Set `output.format` in the blueprint (or rely on the default). Every format writes to
`--output=<path>` or `storage/app/scrapes/<name>.<ext>` when omitted; use `--output=-`
to stream to STDOUT.

| Format | Ext | Best for |
|--------|-----|----------|
| `json` (**default**) | `.json` | Pretty array — human-readable, small crawls |
| `jsonl` | `.jsonl` | One object per line — large crawls, streaming consumers |
| `csv` | `.csv` | Spreadsheets; array fields are JSON-encoded per cell |
| `markdown` | `.md` | **LLM ingestion / RAG** — one Markdown section per item |

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

### Waiting for JS-rendered content

SPAs (Vue/React/Angular) often serve an empty HTML shell and render rows later — a plain
fetch (or even a headless browser that captures too early) sees the loader, not the data.
`--browser-wait-for` tells the headless browser what to wait for before capturing HTML,
and **implies the `browser` transport** when none is chosen:

```bash
# wait for a real data row, not the page skeleton
php artisan datahelm:scrap:generate "https://example.com/candidates" \
  --browser-wait-for=".data-table-row"
```

Accepts a CSS selector or a Puppeteer keyword (`networkidle`, `domcontentloaded`, `load`).
Pick a selector that only appears **when the data is loaded** (a row, a card) — waiting on
the empty table container returns the loader. Baked into the blueprint as
`http_config.browser_wait_for`, so the generated robot waits the same way on every run.

Related generation flags: `--user-agent="..."` bakes a custom User-Agent into
`http_config`; `--stream` sets `output_config.stream` so the robot writes items to disk
as they are scraped instead of buffering.

## Authentication (login-gated sites)

There's no built-in login flow (no automated username/password POST) — instead, the
package **replays a session you captured manually**: log in through your browser, open
DevTools → Network, copy the session cookie or `Authorization` header from an
authenticated request, and pass it in.

**Cookies** — `--cookie` on the CLI, or `http_config.cookies` in the blueprint:

```bash
php artisan datahelm:scrap:generate <url> --cookie="session_id=abc123; auth_token=xyz789"
```

```json
"http_config": {
  "cookies": [
    { "name": "session_id", "value": "abc123" },
    { "name": "auth_token", "value": "xyz789" }
  ]
}
```

**Headers**, including `Authorization: Bearer <token>` — `--header` on the CLI, or
`http_config.headers` (HTML mode) / `api.headers` (API mode):

```bash
php artisan datahelm:scrap:generate <url> --header="Authorization: Bearer eyJhbGciOi..."
```

Both are sent on every request the crawl makes (`GuzzleHttpClient` builds a `CookieJar`
from `cookies` and merges `headers` into every call).

> **No automatic renewal.** Captured cookies/tokens expire (minutes to hours, depending on
> the site) and must be recaptured by hand — there is no login step that runs before the
> crawl to refresh them. This works fine for one-off scrapes; it is **not** a fit for an
> authenticated site that a robot needs to crawl unattended on a schedule.

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
| `datahelm:scrap:run` | Run a blueprint and export items (JSON / JSONL / CSV / Markdown) |
| `datahelm:scrap:shell` | Interactive CSS/XPath selector shell against a live URL |
| `datahelm:scrap:validate` | Validate a blueprint JSON file |
| `datahelm:robot:{name}` | Run a site-specific robot (scaffolded by default; see `--robot-name`) |

## What's new

**Single-page mode** — `--single-page` on `datahelm:scrap:generate` for URLs that are one
record, not a list (an article, a profile, a one-off dashboard page). Skips list detection
and produces `item_selector: "body"` with pagination disabled. Add `--main-content`
(Firecrawl's `onlyMainContent`) to scope detection to the page's primary content region.
See [Single-page mode](#single-page-mode).

**Generation flags** — `--browser-wait-for=<css>` waits for JS-rendered content before
capturing (implies the `browser` transport; see
[Waiting for JS-rendered content](#waiting-for-js-rendered-content)); `--user-agent` and
`--stream` bake their blueprint settings at generation time. The SPA endpoint prompt now
defaults to "None of these" and refuses to scaffold a robot with zero detected fields.

**LLM-ready Markdown output** — the Firecrawl / Crawl4AI feature, now in Laravel.

- **`markdown` field type** — set a field's `type` to `markdown` to render the
  matched element's content as clean Markdown (headings, nested lists, ordered
  lists, links, images, fenced code with language, tables, blockquotes, `<br>`).
  Scripts, styles, and site chrome (`nav`/`header`/`footer`/`aside`) are stripped.
  See [Markdown / LLM-ready output](#markdown--llm-ready-output).
- **`markdown` output format** — set `output.format` to `markdown` to export a whole
  crawl as a single Markdown document, one section per item, ready for an LLM context
  window or a RAG index. See [Output formats](#output-formats).
- **`HtmlToMarkdown` converter** (`DataHelm\Crawler\Markdown\HtmlToMarkdown`) — the
  engine behind both, dependency-free (ext-dom only) and usable on its own.
- **Fix:** `datahelm:scrap:run` now honours the blueprint's `output.format`
  (JSON / JSONL / CSV / Markdown); previously it always wrote JSON.
- **Auto network-capture discovery** — with `--transport=auto`, a static page that
  yields no list is now rendered once in a headless browser with the same
  `--cookie`/`--header`/user-agent as everything else, and its JSON network traffic
  is inspected to auto-discover the site's data API — including on login-gated SPAs.
  Previously this required a manually-supplied `--api-endpoint`.
- **Fix:** cookies without an explicit `domain` (the common case for `--cookie`) are
  now correctly scoped to whichever host each request actually hits, across every
  transport (`guzzle`, `browser`, `auto`) — previously they could silently never be
  sent, breaking authenticated crawls with a valid-looking cookie.
- **Fix:** API-mode pagination now recognises offset-style APIs (`?limit=&offset=`)
  from the endpoint's own query string instead of always assuming `page`/`size`,
  which some strictly-validating APIs rejected with a 400/422.

## License

MIT

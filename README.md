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
php artisan datahelm:scrap:generate "https://example.com/listing" --get-detail=true --robot
php artisan datahelm:robot:example --limit=10
```

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
| `datahelm:robot:{name}` | Run a site-specific robot (scaffolded with `--robot`) |

## What's new

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

## License

MIT

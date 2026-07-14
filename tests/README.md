# Package tests

Regression tests for the DataHelm Crawler package. They exercise the pure-PHP
detection/scraping classes (Symfony DomCrawler + Guzzle, no Laravel), so they run
standalone.

This dev checkout has no `vendor/` of its own — it is symlinked into the host
app's `vendor/`. The test bootstrap (`tests/bootstrap.php`) therefore borrows
PHPUnit and the runtime dependencies from an installed `vendor/`, then registers
PSR-4 autoloading for **this** package's `src/` and `tests/`.

## Running

Point `DATAHELM_VENDOR` at any installed `vendor/autoload.php` that has
`phpunit/phpunit`, `symfony/dom-crawler` and `guzzlehttp/guzzle` (the sibling
`datahelm/crawler` checkout has one), then run PHPUnit with this config:

```bash
# from a host with PHP 8.3+ and an installed vendor/ (e.g. ../../../../crawler):
DATAHELM_VENDOR=/path/to/vendor/autoload.php \
  /path/to/vendor/bin/phpunit --configuration phpunit.xml
```

### Via the Docker stack (no local PHP needed)

```bash
docker compose run --rm --no-deps --entrypoint php \
  -v /abs/path/to/crawler:/pkgvendor \
  -v /abs/path/to/crawler.dev/packages/datahelm/crawler:/pkg \
  -e DATAHELM_VENDOR=/pkgvendor/vendor/autoload.php \
  artisan /pkgvendor/vendor/bin/phpunit --configuration /pkg/phpunit.xml
```

## What's covered

| Test | Guards against |
|------|----------------|
| `Detection/JsonStructureDetectorTest` | API mode picking a facet/aggregation array or a long lookup list instead of the real records (autoscar, primeiramaosaga) |
| `Detection/ImageFieldDetectorTest` | Missing CSS `background-image`/`<picture>` photos (Quasar/Vuetify) and choosing a tracking pixel over a real photo |
| `Detection/SpaDetectorTest` | Failing to recognise a JS-rendered page (Next/Nuxt/Gatsby markers, empty "Loading…" shell) so it's never rendered or API-detected |
| `Scraping/ApiCrawlerQueryTest` | Dropping an endpoint's own query params (`sort`, filters, flags) when injecting pagination — the 400 on token/param-strict APIs |

Each test was verified to **fail** when its corresponding fix is reverted.

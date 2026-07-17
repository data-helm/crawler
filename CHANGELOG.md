# Changelog

All notable changes to `datahelm/crawler` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **TLS certificate verification is now ON by default.** The Guzzle and managed
  scraping-API transports previously disabled peer verification unconditionally,
  exposing every request (including cookies and the scraping-provider API key) to
  man-in-the-middle attacks. Verification can be disabled per blueprint with
  `http_config.verify_tls: false` for sites with broken/self-signed certificates.
- **SSRF guard for outbound requests (`UrlGuard`).** Every fetch — list pages,
  pagination, detail pages (whose URLs come from *scraped* content), and API
  calls — now passes a guard. Non-http(s) schemes (`file://`, `gopher://`, …) are
  always refused. Private/reserved/loopback/link-local hosts (incl. the cloud
  metadata address `169.254.169.254`) are refused when
  `crawler.security.block_private_hosts` is enabled (off by default; `allow_hosts`
  whitelists specific hosts). Recommended ON for multi-tenant / untrusted-blueprint
  setups.

### Fixed

- **Infinite-scroll no longer loops forever.** An endpoint that ignores the
  offset/page parameter and returns the same fragment repeatedly is now detected
  (identical-payload guard) and the crawl stops — matching the existing API-mode
  guard. Previously, with `max_pages: 0`, it could loop until the item limit (or
  indefinitely with no `--limit`).

- **Streaming CSV / Markdown output no longer corrupts.** With
  `output_config.stream: true`, CSV output froze its header row from the first
  item, misaligning or dropping fields on later items with different keys, and
  Markdown was written row-by-row. Both formats now buffer internally and emit one
  correct document via the same exporters the buffered path uses. `json`/`jsonl`
  still stream row-by-row.
- **`item_schema` coercion is now applied in API mode.** The schema-coercion
  pipeline step was only wired into the HTML crawl; API-mode robots ignored
  `item_schema` entirely.
- **Resumable crawls (`--resumable` / `--resume`) now work in API mode.** Dedup
  state was persisted and restored only on the HTML path, so API robots
  re-scraped everything on every run.
- Forward-compatibility: `CsvExporter` passes an explicit `$escape` argument to
  `fputcsv`, silencing the PHP 8.4 deprecation (hard error in PHP 9).

### Changed

- **API mode now reuses the HTML engine's `ItemSink`.** The JSON crawler
  previously reimplemented the whole post-extraction pipeline (dedup, filters,
  images, stats, streaming) inline, which is what let `item_schema` and resumable
  state silently diverge. There is now a single implementation, so HTML and API
  modes stay in lockstep.

### Added

- `http_config.verify_tls` blueprint option (default `true`).
- `crawler.security.block_private_hosts` / `crawler.security.allow_hosts` config
  (env: `CRAWLER_BLOCK_PRIVATE_HOSTS`, `CRAWLER_ALLOW_HOSTS`) for the SSRF guard.
- GitHub Actions CI running the PHPUnit suite on PHP 8.3 and 8.4.
- `require-dev` (PHPUnit), `autoload-dev`, a `composer test` script, and
  `authors`/`support` metadata in `composer.json`, so the suite runs from a clean
  clone.
- Batched upserts in `DatabaseSink` (default 500 rows/query, configurable via
  `$batchSize`) instead of one query per item.
- Regression tests: SSRF guard, `Url::absolute` resolution, `HtmlToMarkdown`,
  every `FilterRule` operator, the output exporters, streaming CSV/Markdown,
  TLS-verify defaults, and API-mode resumable/`item_schema` behaviour. Suite grew
  from 23 to 73 tests.

## [1.0.3] - 2026-07-14

### Fixed

- `--output-format` (jsonl/csv/markdown) is now honoured by generated robot
  commands, not just `datahelm:scrap:run`.

## [1.0.0] - 2026-07-04

- Initial public release.

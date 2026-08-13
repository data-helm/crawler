---
name: Bug report
about: Something isn't working as expected
title: "[Bug] "
labels: bug
assignees: ''
---

## Description

A clear, concise description of what the bug is.

## Steps to reproduce

1. Run `php artisan datahelm:scrap:generate ...` (or whichever command/code path)
2. ...
3. See error / wrong output

## Expected behavior

What you expected to happen.

## Actual behavior

What actually happened. Include the full error message / stack trace if there is one.

## Environment

- `datahelm/crawler` version:
- PHP version:
- Laravel version:
- Transport used (`guzzle` / `browser` / `flaresolverr` / `scraping_api` / `auto`):

## Blueprint / config (if relevant)

If the bug is about detection or a generated blueprint, paste the relevant
blueprint JSON or `config/crawler.php` snippet (redact any URLs/credentials you
don't want public).

## Additional context

Anything else that might help — a link to the page being crawled (if public),
screenshots, etc.

<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * How a blueprint sources its data.
 *
 * HTML — classic server-rendered scraping with CSS/XPath selectors (default).
 * API  — fetch a JSON endpoint directly (for JavaScript SPAs backed by an API)
 *        and extract fields with JSON dot-paths.
 */
enum CrawlMode: string
{
    case HTML = 'html';
    case API  = 'api';

    public static function fromValue(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::HTML;
    }
}

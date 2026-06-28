<?php

namespace DataHelm\Crawler\Scraping;

/**
 * Helpers for blueprint max_pages: 0 means unlimited (crawl until pagination ends).
 */
final class PageCap
{
    public static function isUnlimited(int $maxPages): bool
    {
        return $maxPages === 0;
    }

    /** Whether another page may be added when $currentCount pages are already queued/fetched. */
    public static function allowsMore(int $currentCount, int $maxPages): bool
    {
        return self::isUnlimited($maxPages) || $currentCount < $maxPages;
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    public static function apply(array $urls, int $maxPages): array
    {
        $unique = array_values(array_unique($urls));

        return self::isUnlimited($maxPages) ? $unique : array_slice($unique, 0, $maxPages);
    }
}

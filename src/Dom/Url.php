<?php

namespace DataHelm\Crawler\Dom;

/**
 * Small URL utility for resolving relative links against the page they were
 * found on and extracting host names.
 */
final class Url
{
    public static function absolute(string $base, ?string $link): ?string
    {
        $link = $link !== null ? trim($link) : '';
        if ($link === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $link)) {
            return $link;
        }

        // Links with a non-http scheme (mailto:, javascript:, tel:, data:, …)
        // are not relative paths — resolving them against the base produces
        // garbage URLs the crawler would then try to fetch.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $link)) {
            return null;
        }

        // A fragment-only link points at the page we already have.
        if (str_starts_with($link, '#')) {
            return null;
        }

        $parts = parse_url($base);
        if (! isset($parts['scheme'], $parts['host'])) {
            $base  = self::normalize($base);
            $parts = parse_url($base);
        }
        if (! isset($parts['scheme'], $parts['host'])) {
            return self::isFetchable($link) ? $link : null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($link, '//')) {
            return $parts['scheme'] . ':' . $link;
        }

        if (str_starts_with($link, '/')) {
            return $origin . self::normalizePath($link);
        }

        // A query-only link ("?page=2") keeps the base path and replaces the
        // query — common shape for pagination hrefs.
        if (str_starts_with($link, '?')) {
            return $origin . ($parts['path'] ?? '/') . $link;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . self::normalizePath($dir . $link);
    }

    /**
     * Collapse "." and ".." segments in a path (e.g. /a/b/../../c -> /c).
     */
    private static function normalizePath(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);

                continue;
            }
            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }

    public static function host(string $url): string
    {
        return parse_url(self::normalize($url), PHP_URL_HOST) ?: 'site';
    }

    /**
     * Ensure a user-supplied URL has a scheme. Bare hostnames like
     * "example.com.br" or "example.com/path" become "https://…" so the HTTP
     * client (which rejects schemeless URLs) can fetch them.
     */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return 'https://' . $url;
        }

        return $url;
    }

    /** True when the URL has an http(s) scheme and can be passed to Guzzle. */
    public static function isFetchable(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}

<?php

namespace DataHelm\Crawler\Scraping;

/**
 * Reads values out of a decoded JSON structure using dot-notation paths.
 *
 * Supports:
 *  - associative keys:   "data.results.content"
 *  - numeric list index: "images.0.url"
 *  - empty path "":      returns the whole structure
 *
 * Returns null when any segment is missing, so callers can treat a missing
 * path the same as an absent field.
 */
final class JsonPath
{
    public static function get(mixed $data, string $path): mixed
    {
        if ($path === '') {
            return $data;
        }

        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];

                continue;
            }

            // Numeric index into a list.
            if (is_array($current) && ctype_digit($segment) && array_key_exists((int) $segment, $current)) {
                $current = $current[(int) $segment];

                continue;
            }

            return null;
        }

        return $current;
    }
}

<?php

namespace DataHelm\Crawler\Pipeline;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Normalises whitespace on every string field (collapses runs of whitespace and
 * trims the ends).
 */
final class TrimProcessor implements ItemProcessor
{
    public function process(ScrapedItem $item, string $pageUrl): ScrapedItem
    {
        foreach ($item->toArray() as $key => $value) {
            if (is_string($value)) {
                $item->set($key, trim((string) preg_replace('/\s+/', ' ', $value)));
            }
        }

        return $item;
    }
}

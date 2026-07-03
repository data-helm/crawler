<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Exports scraped items as newline-delimited JSON (one JSON object per line).
 * Better than the JSON array format for large crawls because consumers can
 * stream it line by line without loading the whole file into memory.
 */
final class JsonlExporter implements ItemExporter
{
    public function export(array $items): string
    {
        $lines = array_map(
            static fn (ScrapedItem $item) => (string) json_encode(
                $item->toArray(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            $items,
        );

        return implode("\n", $lines);
    }
}

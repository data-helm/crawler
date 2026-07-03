<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Exports scraped items as a pretty-printed JSON array.
 */
final class JsonExporter implements ItemExporter
{
    public function export(array $items): string
    {
        $data = array_map(static fn (ScrapedItem $item) => $item->toArray(), $items);

        return (string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}

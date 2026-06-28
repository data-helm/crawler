<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Renders scraped items to an output format. Strategy: swap JSON for CSV/NDJSON
 * without touching the engine.
 */
interface ItemExporter
{
    /**
     * @param list<ScrapedItem> $items
     */
    public function export(array $items): string;
}

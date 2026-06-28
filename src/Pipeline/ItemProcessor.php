<?php

namespace DataHelm\Crawler\Pipeline;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * One stage of the item-processing chain. Each processor receives an item and
 * returns it (possibly transformed) for the next stage. Mirrors Scrapy's item
 * pipeline.
 */
interface ItemProcessor
{
    public function process(ScrapedItem $item, string $pageUrl): ScrapedItem;
}

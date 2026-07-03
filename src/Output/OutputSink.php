<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Destination for scraped items.
 *
 * Where {@see ItemExporter} renders a buffered list to a string, an OutputSink
 * receives items one at a time and decides what to do with them — write a file,
 * insert a database row, push onto a queue, POST to a webhook, index in
 * Elasticsearch, … Implement this contract and bind it via config('crawler.sink')
 * (or pass an instance to ScrapesToConsole::crawlToSink) to send results
 * straight into your own systems.
 *
 * Lifecycle: open() once, write() per item, close() once.
 */
interface OutputSink
{
    /**
     * Begin a run. $name is a stable identifier for the scrape (host or robot
     * name) implementations can use for filenames, table partitions, etc.
     */
    public function open(string $name): void;

    public function write(ScrapedItem $item): void;

    /**
     * Finish the run and flush/close any resources. Returns a short,
     * human-readable description of where the items went (for CLI output).
     */
    public function close(): string;
}

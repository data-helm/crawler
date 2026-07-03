<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * An {@see OutputSink} that hands each item to a callback — the simplest way to
 * push scraped data into a database, queue, webhook, or any custom destination
 * without writing a dedicated sink class.
 *
 * Example (inside a robot):
 *
 *   $sink = new CallbackSink(function (ScrapedItem $item) {
 *       Listing::updateOrCreate(['url' => $item->get('link')], $item->toArray());
 *   }, 'database');
 *   $this->crawlToSink($engine, $blueprint, $sink, $limit);
 */
final class CallbackSink implements OutputSink
{
    private int $count = 0;

    /**
     * @param (callable(ScrapedItem,string):void) $onItem  Receives the item and run name.
     */
    public function __construct(
        private readonly mixed $onItem,
        private readonly string $label = 'callback',
    ) {
    }

    public function open(string $name): void
    {
        $this->name  = $name;
        $this->count = 0;
    }

    private string $name = '';

    public function write(ScrapedItem $item): void
    {
        ($this->onItem)($item, $this->name);
        $this->count++;
    }

    public function close(): string
    {
        return sprintf('%s (%d item(s))', $this->label, $this->count);
    }
}

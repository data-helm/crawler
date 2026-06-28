<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Dispatches each scraped item as a Laravel job onto a queue.
 *
 * The job class must accept a ScrapedItem (or its array representation) in its
 * constructor. Two calling conventions are supported via $passArray:
 *
 *   false (default) — new MyJob(ScrapedItem $item)
 *   true            — new MyJob(array $data)
 *
 * Usage:
 *   new QueueSink(
 *       job:       ProcessAuction::class,
 *       queue:     'auctions',
 *       passArray: true,
 *   );
 *
 * @phpstan-type JobClass class-string<object>
 */
class QueueSink implements OutputSink
{
    private int $count = 0;

    /**
     * @param class-string $job       Dispatchable job class.
     * @param string       $queue     Queue name ('' = default connection queue).
     * @param bool         $passArray Pass item as array instead of ScrapedItem.
     * @param string       $connection Queue connection name ('' = default).
     */
    public function __construct(
        private readonly string $job,
        private readonly string $queue = '',
        private readonly bool $passArray = false,
        private readonly string $connection = '',
    ) {
    }

    public function open(string $name): void
    {
        $this->count = 0;
    }

    public function write(ScrapedItem $item): void
    {
        $payload = $this->passArray ? $item->toArray() : $item;

        $dispatch = dispatch(new $this->job($payload));

        if ($this->queue !== '') {
            $dispatch->onQueue($this->queue);
        }

        if ($this->connection !== '') {
            $dispatch->onConnection($this->connection);
        }

        $this->count++;
    }

    public function close(): string
    {
        $queueLabel = $this->queue !== '' ? "'{$this->queue}'" : 'default';

        return sprintf('%d job(s) dispatched to %s queue (%s)', $this->count, $queueLabel, $this->job);
    }
}

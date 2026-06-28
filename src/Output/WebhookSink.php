<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * POSTs each scraped item as JSON to an HTTP webhook endpoint.
 *
 * Usage:
 *   new WebhookSink(
 *       url:     'https://api.example.com/items',
 *       headers: ['Authorization' => 'Bearer token123'],
 *       method:  'POST',
 *   );
 *
 * For high-volume scrapes, set $batchSize > 1 to accumulate items and POST
 * them in arrays instead of one-at-a-time.
 *
 * Errors are non-fatal by default ($throwOnError = false); they are written
 * to STDERR and the crawl continues.
 */
class WebhookSink implements OutputSink
{
    /** @var list<array<string,mixed>> */
    private array $buffer = [];
    private int $sent = 0;
    private int $failed = 0;

    /**
     * @param array<string,string> $headers    Extra HTTP headers (e.g. Authorization).
     */
    public function __construct(
        private readonly string $url,
        private readonly array $headers = [],
        private readonly string $method = 'POST',
        private readonly int $batchSize = 1,
        private readonly int $timeoutSeconds = 10,
        private readonly bool $throwOnError = false,
    ) {
    }

    public function open(string $name): void
    {
        $this->buffer  = [];
        $this->sent    = 0;
        $this->failed  = 0;
    }

    public function write(ScrapedItem $item): void
    {
        $this->buffer[] = $item->toArray();

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function close(): string
    {
        if ($this->buffer !== []) {
            $this->flush();
        }

        return sprintf(
            '%d item(s) sent to %s (%d failed)',
            $this->sent,
            $this->url,
            $this->failed,
        );
    }

    private function flush(): void
    {
        $payload = $this->batchSize === 1 && count($this->buffer) === 1
            ? $this->buffer[0]
            : $this->buffer;

        $this->buffer = [];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->failed++;

            return;
        }

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], array_map(
            static fn (string $k, string $v): string => "{$k}: {$v}",
            array_keys($this->headers),
            array_values($this->headers),
        ));

        $ctx = stream_context_create([
            'http' => [
                'method'        => strtoupper($this->method),
                'header'        => implode("\r\n", $headers),
                'content'       => $json,
                'timeout'       => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($this->url, false, $ctx);

        if ($result === false) {
            $this->failed += is_array($payload) ? count($payload) : 1;
            $msg = "WebhookSink: request to {$this->url} failed";

            if ($this->throwOnError) {
                throw new \RuntimeException($msg);
            }

            fwrite(STDERR, $msg . PHP_EOL);

            return;
        }

        $this->sent += is_array($payload) ? count($payload) : 1;
    }
}

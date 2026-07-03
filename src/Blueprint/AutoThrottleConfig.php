<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * Dynamic delay adjustment inspired by Scrapy's AutoThrottle extension.
 *
 * After each page fetch, the actual server response latency is measured.
 * The delay before the *next* request is adjusted toward:
 *
 *   target_delay = latency_ms / target_concurrency
 *
 * using an exponential moving average so it doesn't swing wildly:
 *
 *   new_delay = (prev_delay + clamp(target_delay, start_delay, max_delay)) / 2
 *
 * The result is clamped to [http_config.delay_ms, max_delay_ms].
 * When disabled, http_config.delay_ms is used as a fixed delay (the old behaviour).
 *
 * target_concurrency — expected parallel requests (keep at 1.0 for sequential crawls).
 * start_delay_ms     — initial delay before the first page is fetched.
 * max_delay_ms       — upper bound on the computed delay (safety cap).
 * debug              — write computed delay to STDERR after each page.
 */
final class AutoThrottleConfig
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly float $targetConcurrency = 1.0,
        public readonly int $startDelayMs = 0,
        public readonly int $maxDelayMs = 30000,
        public readonly bool $debug = false,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            enabled:           (bool) ($data['enabled'] ?? false),
            targetConcurrency: max(0.1, (float) ($data['target_concurrency'] ?? 1.0)),
            startDelayMs:      max(0, (int) ($data['start_delay_ms'] ?? 0)),
            maxDelayMs:        max(0, (int) ($data['max_delay_ms'] ?? 30000)),
            debug:             (bool) ($data['debug'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'enabled'            => $this->enabled,
            'target_concurrency' => $this->targetConcurrency,
            'start_delay_ms'     => $this->startDelayMs,
            'max_delay_ms'       => $this->maxDelayMs,
            'debug'              => $this->debug,
        ];
    }
}

<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * Crawl-loop timing and hard item cap.
 *
 * delay_between_pages_ms  — sleep after finishing all items on one page before
 *                           moving to the next pagination page.
 * delay_between_items_ms  — sleep after processing each individual item
 *                           (detail fetch + image download).
 * max_items               — blueprint-level hard cap: stop after N items regardless
 *                           of pagination. 0 = no cap. The per-run --limit flag
 *                           takes precedence when non-zero.
 */
final class CrawlConfig
{
    public function __construct(
        public readonly int $delayBetweenPagesMs = 0,
        public readonly int $delayBetweenItemsMs = 0,
        public readonly int $maxItems = 0,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            delayBetweenPagesMs: max(0, (int) ($data['delay_between_pages_ms'] ?? 0)),
            delayBetweenItemsMs: max(0, (int) ($data['delay_between_items_ms'] ?? 0)),
            maxItems:            max(0, (int) ($data['max_items'] ?? 0)),
        );
    }

    public function toArray(): array
    {
        return [
            'delay_between_pages_ms' => $this->delayBetweenPagesMs,
            'delay_between_items_ms' => $this->delayBetweenItemsMs,
            'max_items'              => $this->maxItems,
        ];
    }
}

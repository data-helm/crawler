<?php

namespace DataHelm\Crawler\Scraping;

use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Media\ItemImageResolver;
use DataHelm\Crawler\Pipeline\ItemPipeline;

/**
 * Collects extracted items for the HTML crawl, applying the shared post-
 * extraction steps in order: pipeline processing, deduplication, conditional
 * filters, image downloading, stats, and either streaming or buffering.
 *
 * Used by both the normal pagination loop and the infinite_scroll loop so their
 * item handling stays identical.
 *
 * When a {@see CrawlState} is provided, its seenKeys are pre-loaded so
 * previously scraped items are skipped (resumable crawls, --resume flag).
 */
final class ItemSink
{
    /** @var list<ScrapedItem> */
    private array $items = [];

    /** @var array<string,bool> */
    private array $seenKeys = [];

    /**
     * @param (callable(ScrapedItem):void)|null $onItem
     */
    public function __construct(
        private readonly ScrapeBlueprint $blueprint,
        private readonly ItemPipeline $pipeline,
        private readonly ItemImageResolver $imageResolver,
        private readonly CrawlStats $stats,
        private readonly int $effectiveLimit,
        private readonly bool $streaming,
        private readonly mixed $onItem = null,
        private readonly ?CrawlState $resumeState = null,
    ) {
        // Pre-populate seenKeys from persisted state for resumable crawls.
        if ($resumeState !== null) {
            $this->seenKeys = $resumeState->seenKeys;
        }
    }

    /**
     * Process and record one item.
     *
     * @return bool true when the configured item limit has been reached and the
     *              crawl should stop.
     */
    public function accept(ScrapedItem $item, string $contextUrl): bool
    {
        $item = $this->pipeline->process($item, $contextUrl);

        if ($this->blueprint->dedup->enabled) {
            $key = (string) $item->get($this->blueprint->dedup->keyField);
            if ($key !== '' && isset($this->seenKeys[$key])) {
                $this->stats->itemsDeduped++;

                return false;
            }
            if ($key !== '') {
                $this->seenKeys[$key] = true;
                $this->resumeState?->markSeen($key);
            }
        }

        if (! $this->blueprint->filters->passes($item)) {
            $this->stats->itemsFiltered++;

            return false;
        }

        if ($this->blueprint->getPrimaryImage || $this->blueprint->getAllImages || $this->blueprint->getGalleryImages) {
            $this->imageResolver->enrich($item, $this->blueprint);
        }

        $this->stats->itemsScraped++;
        if ($this->resumeState !== null) {
            $this->resumeState->itemCount++;
        }

        if ($this->streaming && $this->onItem !== null) {
            ($this->onItem)($item);
        } else {
            $this->items[] = $item;
        }

        if ($this->blueprint->crawlConfig->delayBetweenItemsMs > 0) {
            usleep($this->blueprint->crawlConfig->delayBetweenItemsMs * 1000);
        }

        $count = $this->streaming ? $this->stats->itemsScraped : count($this->items);

        return $this->effectiveLimit > 0 && $count >= $this->effectiveLimit;
    }

    /**
     * Final item list with output transforms applied (empty when streaming).
     *
     * @return list<ScrapedItem>
     */
    public function results(): array
    {
        return $this->streaming ? [] : $this->blueprint->outputConfig->applyToItems($this->items);
    }
}

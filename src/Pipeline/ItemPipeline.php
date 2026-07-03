<?php

namespace DataHelm\Crawler\Pipeline;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Runs an item through an ordered chain of {@see ItemProcessor}s.
 */
final class ItemPipeline
{
    /**
     * @param list<ItemProcessor> $processors
     */
    public function __construct(private readonly array $processors)
    {
    }

    public function process(ScrapedItem $item, string $pageUrl): ScrapedItem
    {
        foreach ($this->processors as $processor) {
            $item = $processor->process($item, $pageUrl);
        }

        return $item;
    }

    /**
     * Return a new pipeline with an additional processor appended at the end.
     * The original pipeline is unchanged (immutable).
     */
    public function withExtra(ItemProcessor $processor): self
    {
        return new self([...$this->processors, $processor]);
    }
}

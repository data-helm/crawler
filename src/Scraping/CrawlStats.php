<?php

namespace DataHelm\Crawler\Scraping;

/**
 * Mutable counters accumulated by CrawlEngine during a single crawl() run.
 * Retrieve via CrawlEngine::getLastStats() after the crawl completes.
 */
final class CrawlStats
{
    private float $startTime;
    private float $endTime = 0.0;

    public int $pagesFetched      = 0;
    public int $pagesFailed       = 0;
    public int $itemsScraped      = 0;
    public int $itemsFiltered     = 0;
    public int $itemsDeduped      = 0;
    public int $detailFetched     = 0;
    public int $detailFailed      = 0;
    public int $imagesSaved       = 0;
    public int $imagesFailed      = 0;
    public int $cacheHits         = 0;
    public int $cacheMisses       = 0;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function finish(): void
    {
        $this->endTime = microtime(true);
    }

    public function elapsedSeconds(): float
    {
        $end = $this->endTime > 0.0 ? $this->endTime : microtime(true);

        return round($end - $this->startTime, 2);
    }

    public function itemsPerSecond(): float
    {
        $elapsed = $this->elapsedSeconds();

        return $elapsed > 0 ? round($this->itemsScraped / $elapsed, 1) : 0.0;
    }

    /**
     * Human-readable summary line.
     */
    public function summary(): string
    {
        $elapsed = $this->elapsedSeconds();
        $mins    = (int) floor($elapsed / 60);
        $secs    = (int) ($elapsed % 60);
        $time    = $mins > 0 ? "{$mins}m{$secs}s" : "{$secs}s";

        $lines = [
            sprintf('  Items scraped : %d in %s (%.1f/s)', $this->itemsScraped, $time, $this->itemsPerSecond()),
            sprintf('  Pages         : %d fetched, %d failed', $this->pagesFetched, $this->pagesFailed),
        ];

        if ($this->detailFetched > 0 || $this->detailFailed > 0) {
            $lines[] = sprintf('  Detail pages  : %d fetched, %d failed', $this->detailFetched, $this->detailFailed);
        }

        if ($this->itemsFiltered > 0) {
            $lines[] = sprintf('  Filtered out  : %d items', $this->itemsFiltered);
        }

        if ($this->itemsDeduped > 0) {
            $lines[] = sprintf('  Deduped       : %d items', $this->itemsDeduped);
        }

        if ($this->imagesSaved > 0 || $this->imagesFailed > 0) {
            $lines[] = sprintf('  Images        : %d saved, %d failed', $this->imagesSaved, $this->imagesFailed);
        }

        if ($this->cacheHits > 0 || $this->cacheMisses > 0) {
            $lines[] = sprintf('  Cache         : %d hits, %d misses', $this->cacheHits, $this->cacheMisses);
        }

        return implode(PHP_EOL, $lines);
    }
}

<?php

namespace DataHelm\Crawler\Output;

/**
 * Default {@see OutputSink}: writes a single pretty JSON array file per scrape
 * under the configured output directory. Resolved by the container from
 * config('crawler.sink').
 */
final class JsonFileSink extends FileSink
{
    public function __construct(string $outputDir, ?string $destination = null)
    {
        parent::__construct($outputDir, 'json', $destination);
    }
}

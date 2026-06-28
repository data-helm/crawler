<?php

namespace DataHelm\Crawler\Scraping;

/**
 * A single extracted record. Kept as a thin keyed bag so blueprints can define
 * arbitrary fields without a fixed schema (Scrapy's "Item").
 */
final class ScrapedItem
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(private array $data = [])
    {
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}

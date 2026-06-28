<?php

namespace DataHelm\Crawler\Blueprint;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Controls how scraped items are shaped and serialised on the way out.
 *
 * format         — output format: json (pretty array), jsonl (one object per line),
 *                  or csv (comma-separated, first row = headers).
 * stream         — write items to disk as they are scraped instead of buffering
 *                  all in memory. Useful for large crawls (thousands of items).
 * flatten        — collapse nested arrays: saved_images[0] → saved_images_0.
 * exclude_fields — field names to strip from every item before export.
 * rename_fields  — map old field names to new ones: {"old" => "new"}.
 */
final class OutputConfig
{
    /**
     * @param list<string>         $excludeFields
     * @param array<string,string> $renameFields
     */
    public function __construct(
        public readonly string $format = 'json',
        public readonly bool $stream = false,
        public readonly bool $flatten = false,
        public readonly array $excludeFields = [],
        public readonly array $renameFields = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        $format = (string) ($data['format'] ?? 'json');

        return new self(
            format:        in_array($format, ['json', 'jsonl', 'csv'], true) ? $format : 'json',
            stream:        (bool) ($data['stream'] ?? false),
            flatten:       (bool) ($data['flatten'] ?? false),
            excludeFields: is_array($data['exclude_fields'] ?? null) ? array_values($data['exclude_fields']) : [],
            renameFields:  is_array($data['rename_fields'] ?? null) ? $data['rename_fields'] : [],
        );
    }

    public function toArray(): array
    {
        return [
            'format'         => $this->format,
            'stream'         => $this->stream,
            'flatten'        => $this->flatten,
            'exclude_fields' => $this->excludeFields,
            'rename_fields'  => $this->renameFields,
        ];
    }

    /**
     * Apply all transformations to a list of items.
     *
     * @param list<ScrapedItem> $items
     * @return list<ScrapedItem>
     */
    public function applyToItems(array $items): array
    {
        if ($this->excludeFields === [] && $this->renameFields === [] && ! $this->flatten) {
            return $items;
        }

        return array_values(array_map(fn (ScrapedItem $item) => $this->applyToItem($item), $items));
    }

    private function applyToItem(ScrapedItem $item): ScrapedItem
    {
        $data = $item->toArray();

        foreach ($this->excludeFields as $field) {
            unset($data[$field]);
        }

        foreach ($this->renameFields as $old => $new) {
            if (array_key_exists($old, $data)) {
                $data[$new] = $data[$old];
                unset($data[$old]);
            }
        }

        if ($this->flatten) {
            $data = $this->flattenArray($data);
        }

        return new ScrapedItem($data);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function flattenArray(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix . '_' . $key : (string) $key;

            if (is_array($value)) {
                foreach ($this->flattenArray($value, $fullKey) as $k => $v) {
                    $result[$k] = $v;
                }
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }
}

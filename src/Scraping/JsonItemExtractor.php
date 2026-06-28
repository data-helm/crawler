<?php

namespace DataHelm\Crawler\Scraping;

use DataHelm\Crawler\Blueprint\FieldSelector;

/**
 * The JSON-mode counterpart to {@see ItemExtractor}.
 *
 * Applies a set of {@see FieldSelector}s to a decoded JSON item (an associative
 * array) and produces a {@see ScrapedItem}. The field's `css` property holds the
 * JSON dot-path; `regex` and `multiple` behave just like in HTML mode.
 *
 * Only fields whose type is "json" are read here; others are ignored so a single
 * blueprint could, in principle, mix sources.
 */
final class JsonItemExtractor
{
    /**
     * @param list<FieldSelector> $fields
     */
    public function __construct(private readonly array $fields)
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function extract(array $data): ScrapedItem
    {
        $item = new ScrapedItem();

        foreach ($this->fields as $field) {
            $item->set($field->name, $this->value($data, $field));
        }

        return $item;
    }

    private function value(array $data, FieldSelector $field): mixed
    {
        $raw = JsonPath::get($data, $field->css);

        if ($field->multiple) {
            $list = is_array($raw) ? array_values($raw) : ($raw === null ? [] : [$raw]);

            return array_values(array_filter(
                array_map(fn ($v) => $this->scalarize($v, $field), $list),
                static fn ($v) => $v !== null && $v !== '',
            ));
        }

        return $this->scalarize($raw, $field);
    }

    private function scalarize(mixed $raw, FieldSelector $field): mixed
    {
        // Preserve nested objects/arrays as-is unless a regex is requested.
        if (is_array($raw)) {
            return $field->regex !== null ? null : $raw;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        $value = $raw === null ? null : (is_scalar($raw) ? trim((string) $raw) : null);

        return $this->applyRegex($value, $field);
    }

    private function applyRegex(mixed $value, FieldSelector $field): mixed
    {
        if ($field->regex !== null && is_string($value) && $value !== '') {
            return preg_match($field->regex, $value, $m) ? ($m[1] ?? $m[0]) : null;
        }

        return $value;
    }
}

<?php

namespace DataHelm\Crawler\Pipeline;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Coerces item fields to declared types from the blueprint's item_schema.
 *
 * item_schema format (in blueprint JSON):
 *
 *   "item_schema": {
 *       "title":  "string",
 *       "price":  "float",
 *       "active": "bool",
 *       "year":   "int",
 *       "images": "string[]",
 *       "tags":   "string[]"
 *   }
 *
 * Supported types:
 *   string   — (string) cast; trims whitespace.
 *   int      — (int) cast.
 *   float    — cleans currency symbols/thousands separators, then (float) cast.
 *   bool     — accepts 1/0/true/false/'yes'/'no'/'sim'/'não'.
 *   string[] — ensures the field is a list of strings; wraps a scalar in [].
 *   int[]    — list of ints.
 *   float[]  — list of floats.
 *
 * Missing required fields emit a warning to STDERR but do NOT abort the item.
 */
final class SchemaCoercionProcessor implements ItemProcessor
{
    /**
     * @param array<string,string> $schema  field → type mapping.
     * @param bool                 $strict  Emit STDERR warnings for missing fields.
     */
    public function __construct(
        private readonly array $schema,
        private readonly bool $strict = false,
    ) {
    }

    public function process(ScrapedItem $item, string $pageUrl = ''): ScrapedItem
    {
        foreach ($this->schema as $field => $type) {
            $value = $item->get($field);

            if ($value === null) {
                if ($this->strict) {
                    fwrite(STDERR, "SchemaCoercionProcessor: field '{$field}' missing from item." . PHP_EOL);
                }
                continue;
            }

            $item->set($field, $this->coerce($value, $type));
        }

        return $item;
    }

    private function coerce(mixed $value, string $type): mixed
    {
        return match ($type) {
            'string'   => $this->toString($value),
            'int'      => $this->toInt($value),
            'float'    => $this->toFloat($value),
            'bool'     => $this->toBool($value),
            'string[]' => $this->toList($value, 'string'),
            'int[]'    => $this->toList($value, 'int'),
            'float[]'  => $this->toList($value, 'float'),
            default    => $value,
        };
    }

    private function toString(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' ', array_filter(array_map('strval', $value)));
        }

        return trim((string) $value);
    }

    private function toInt(mixed $value): int
    {
        return (int) preg_replace('/[^\d\-]/', '', (string) $value);
    }

    private function toFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        $s = (string) $value;

        // Strip currency symbols, spaces, thousand separators.
        $s = preg_replace('/[^\d,.\-]/', '', $s) ?? $s;

        // Detect comma-as-decimal (e.g. "1.234,56" → "1234.56").
        if (preg_match('/,\d{1,2}$/', $s)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return (float) $s;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $lower = strtolower(trim((string) $value));

        return in_array($lower, ['1', 'true', 'yes', 'sim', 'on', 'ativo', 'active'], true);
    }

    /**
     * @return list<mixed>
     */
    private function toList(mixed $value, string $elementType): array
    {
        $items = is_array($value) ? array_values($value) : [$value];

        return array_map(fn (mixed $v): mixed => $this->coerce($v, $elementType), $items);
    }
}

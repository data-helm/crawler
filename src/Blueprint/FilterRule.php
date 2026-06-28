<?php

namespace DataHelm\Crawler\Blueprint;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * A single field-level condition used by FiltersConfig.
 *
 * Supported operators:
 *   not_empty    — field has a non-empty value
 *   empty        — field is missing or empty
 *   contains     — field string contains value
 *   not_contains — field string does not contain value
 *   equals       — field string equals value exactly
 *   not_equals   — field string does not equal value
 *   matches      — field string matches the regex in value (e.g. "/R\$\s*\d/")
 *   gt           — numeric field > value
 *   lt           — numeric field < value
 */
final class FilterRule
{
    public function __construct(
        public readonly string $field,
        public readonly string $operator,
        public readonly ?string $value = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            field:    (string) ($data['field'] ?? ''),
            operator: (string) ($data['operator'] ?? 'not_empty'),
            value:    isset($data['value']) && $data['value'] !== null ? (string) $data['value'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'field'    => $this->field,
            'operator' => $this->operator,
            'value'    => $this->value,
        ];
    }

    public function passes(ScrapedItem $item): bool
    {
        $raw = $item->get($this->field);
        $str = is_array($raw)
            ? implode(' ', array_filter($raw, 'is_string'))
            : (string) ($raw ?? '');

        return match ($this->operator) {
            'not_empty'    => $str !== '',
            'empty'        => $str === '',
            'contains'     => $this->value !== null && str_contains($str, $this->value),
            'not_contains' => $this->value === null || ! str_contains($str, $this->value),
            'equals'       => $str === (string) $this->value,
            'not_equals'   => $str !== (string) $this->value,
            'matches'      => $this->value !== null && (bool) @preg_match($this->value, $str),
            'gt'           => $this->value !== null && is_numeric($str) && (float) $str > (float) $this->value,
            'lt'           => $this->value !== null && is_numeric($str) && (float) $str < (float) $this->value,
            default        => true,
        };
    }
}

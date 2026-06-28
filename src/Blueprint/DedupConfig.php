<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * Deduplication: drop any item whose key_field value has already been seen
 * in the current run. Useful when pagination overlaps or when the same robot
 * is re-run against a live listing that hasn't changed much.
 *
 * key_field — the item field used as the uniqueness key (default: "link").
 */
final class DedupConfig
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $keyField = 'link',
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            enabled:  (bool) ($data['enabled'] ?? false),
            keyField: (string) ($data['key_field'] ?? 'link'),
        );
    }

    public function toArray(): array
    {
        return [
            'enabled'   => $this->enabled,
            'key_field' => $this->keyField,
        ];
    }
}

<?php

namespace DataHelm\Crawler\Blueprint;

use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * A collection of FilterRules applied conjunctively (ALL must pass).
 * Items that fail any rule are silently dropped by CrawlEngine.
 */
final class FiltersConfig
{
    /**
     * @param list<FilterRule> $rules
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly array $rules = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        $rules = [];
        foreach ($data['rules'] ?? [] as $rule) {
            if (is_array($rule)) {
                $rules[] = FilterRule::fromArray($rule);
            }
        }

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            rules:   $rules,
        );
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'rules'   => array_map(static fn (FilterRule $r) => $r->toArray(), $this->rules),
        ];
    }

    public function passes(ScrapedItem $item): bool
    {
        if (! $this->enabled || $this->rules === []) {
            return true;
        }

        foreach ($this->rules as $rule) {
            if (! $rule->passes($item)) {
                return false;
            }
        }

        return true;
    }
}

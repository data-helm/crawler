<?php

namespace DataHelm\Crawler\Blueprint;

use DataHelm\Crawler\Dom\Url;

/**
 * One entry in a blueprint's {@see ScrapeBlueprint::$searchFilters}.
 *
 * A search filter is a URL **suffix** (relative to the blueprint's base `url`,
 * e.g. "roupas-femininas/vestidos/") plus optional **meta** tags merged into
 * every item scraped from it — typically the category:
 *
 *   { "url_sufix": "roupas-femininas/vestidos/", "category": "vestido-feminino", "limit": 40 }
 *
 * Keeping the suffix relative means the base URL lives in one place (`url`) and
 * each filter just names the path. An absolute suffix ("https://…") is also
 * accepted and used as-is. A bare string entry carries no tags. An optional
 * per-filter `limit` caps how many items this filter contributes (0 = unlimited),
 * so one robot can take e.g. 40 items from each category instead of letting the
 * first category consume the whole global --limit.
 */
final class SearchFilter
{
    /**
     * @param array<string,scalar> $meta
     */
    public function __construct(
        public readonly string $urlSuffix,
        public readonly array $meta = [],
        public readonly int $limit = 0,
    ) {
    }

    /**
     * Normalise a raw entry: a string suffix, or an array with `url_sufix`
     * (or legacy `url`), an optional `limit`, plus extra tag keys.
     */
    public static function fromMixed(string|array $entry): self
    {
        if (is_string($entry)) {
            return new self($entry);
        }

        $suffix = (string) ($entry['url_sufix'] ?? $entry['url'] ?? '');
        $limit  = max(0, (int) ($entry['limit'] ?? 0));

        $meta = $entry;
        // url/suffix and limit are control keys, not item tags.
        unset($meta['url_sufix'], $meta['url'], $meta['limit']);

        // Keep only scalar tags so they serialise into each item's JSON.
        $meta = array_filter($meta, static fn ($v): bool => is_scalar($v));

        /** @var array<string,scalar> $meta */
        return new self($suffix, $meta, $limit);
    }

    /** Absolute URL to crawl: the suffix resolved against the blueprint base. */
    public function resolvedUrl(string $base): string
    {
        $suffix = trim($this->urlSuffix);
        if ($suffix === '') {
            return $base;
        }
        if (preg_match('#^https?://#i', $suffix) === 1) {
            return Url::normalize($suffix);
        }

        return rtrim($base, '/') . '/' . ltrim($suffix, '/');
    }

    /**
     * Serialise back: a bare string when there are no tags and no limit, otherwise
     * an object with `url_sufix`, optional `limit`, plus the tag keys.
     *
     * @return string|array<string,scalar>
     */
    public function toArrayOrString(): string|array
    {
        if ($this->meta === [] && $this->limit === 0) {
            return $this->urlSuffix;
        }

        $out = ['url_sufix' => $this->urlSuffix];
        if ($this->limit > 0) {
            $out['limit'] = $this->limit;
        }

        return array_merge($out, $this->meta);
    }
}

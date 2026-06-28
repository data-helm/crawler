<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * File-based HTTP response cache applied during crawls.
 *
 * When enabled, every fetched page is stored on disk. Re-running the robot
 * within the TTL window replays from cache instead of hitting the live site —
 * ideal when iterating on selectors during development.
 *
 * path        — directory under storage_path() where cached responses live.
 * ttl_seconds — how long a cached response is considered fresh (default 1 hour).
 *               Set to 0 to cache forever (only cleared manually).
 */
final class CacheConfig
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $path = 'app/cache/http',
        public readonly int $ttlSeconds = 3600,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            enabled:    (bool) ($data['enabled'] ?? false),
            path:       (string) ($data['path'] ?? 'app/cache/http'),
            ttlSeconds: max(0, (int) ($data['ttl_seconds'] ?? 3600)),
        );
    }

    public function toArray(): array
    {
        return [
            'enabled'     => $this->enabled,
            'path'        => $this->path,
            'ttl_seconds' => $this->ttlSeconds,
        ];
    }
}

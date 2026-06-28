<?php

namespace DataHelm\Crawler\Http;

use DataHelm\Crawler\Blueprint\CacheConfig;

/**
 * Transparent file-based cache decorator for any {@see HttpClient}.
 *
 * Cache keys are sha1(url). Files are stored under
 * storage_path({config.path}/{host}/{sha1}.html).
 * Expiry is determined by comparing the file mtime against config.ttl_seconds
 * (ttl_seconds = 0 means cache forever until manually cleared).
 *
 * Cache hits/misses are tracked so CrawlStats can report them.
 */
final class CachedHttpClient implements HttpClient
{
    private int $hits  = 0;
    private int $misses = 0;

    public function __construct(
        private readonly HttpClient $inner,
        private readonly CacheConfig $config,
    ) {
    }

    public function get(string $url): string
    {
        $cacheFile = $this->cacheFile($url);

        if ($this->isFresh($cacheFile)) {
            $this->hits++;

            return (string) file_get_contents($cacheFile);
        }

        $content = $this->inner->get($url);
        $this->persist($cacheFile, $content);
        $this->misses++;

        return $content;
    }

    public function hits(): int
    {
        return $this->hits;
    }

    public function misses(): int
    {
        return $this->misses;
    }

    public function getInner(): HttpClient
    {
        return $this->inner;
    }

    // -------------------------------------------------------------------------

    private function cacheFile(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = preg_replace('/[^a-zA-Z0-9._-]/', '_', $host ?? 'unknown');
        $dir  = storage_path($this->config->path . '/' . $host);

        return $dir . '/' . sha1($url) . '.html';
    }

    private function isFresh(string $path): bool
    {
        if (! file_exists($path)) {
            return false;
        }

        if ($this->config->ttlSeconds === 0) {
            return true;
        }

        return (time() - (int) filemtime($path)) < $this->config->ttlSeconds;
    }

    private function persist(string $path, string $content): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, $content);
    }
}

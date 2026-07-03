<?php

namespace DataHelm\Crawler\Scraping;

/**
 * Persistent dedup state for resumable crawls.
 *
 * When a blueprint has `resumable = true`, the crawl engine saves the set of
 * already-seen dedup keys after each run. On the next run (with --resume flag),
 * the state is restored so only new items are processed.
 *
 * State is stored as JSON at:
 *   storage/app/scrapes/.state/{name}.json
 *
 * Usage from a robot command:
 *   protected $signature = 'datahelm:robot:zuk {--limit=0} {--resume}';
 *
 *   public function handle(CrawlEngine $engine): int
 *   {
 *       if ($this->option('resume')) {
 *           $engine->loadState($this->defaultScrapeName());
 *       }
 *       ...
 *   }
 */
final class CrawlState
{
    /**
     * @param array<string,true> $seenKeys   Previously-seen dedup key values.
     * @param int                $itemCount  Total items scraped across all runs.
     * @param string             $updatedAt  ISO-8601 timestamp of last save.
     */
    public function __construct(
        public array $seenKeys = [],
        public int $itemCount = 0,
        public string $updatedAt = '',
    ) {
    }

    // -------------------------------------------------------------------------

    public static function loadFor(string $name): self
    {
        $path = self::statePath($name);

        if (! file_exists($path)) {
            return new self();
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return new self();
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return new self();
        }

        $seenKeys = [];
        foreach ((array) ($data['seen_keys'] ?? []) as $key) {
            $seenKeys[(string) $key] = true;
        }

        return new self(
            seenKeys:  $seenKeys,
            itemCount: (int) ($data['item_count'] ?? 0),
            updatedAt: (string) ($data['updated_at'] ?? ''),
        );
    }

    public function saveFor(string $name): void
    {
        $path = self::statePath($name);
        $dir  = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->updatedAt = date('c');

        file_put_contents($path, json_encode([
            'name'       => $name,
            'seen_keys'  => array_keys($this->seenKeys),
            'item_count' => $this->itemCount,
            'updated_at' => $this->updatedAt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function clearFor(string $name): void
    {
        $path = self::statePath($name);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function hasSeen(string $key): bool
    {
        return isset($this->seenKeys[$key]);
    }

    public function markSeen(string $key): void
    {
        $this->seenKeys[$key] = true;
    }

    // -------------------------------------------------------------------------

    private static function statePath(string $name): string
    {
        return storage_path('app/scrapes/.state/' . $name . '.json');
    }
}

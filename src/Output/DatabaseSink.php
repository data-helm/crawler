<?php

namespace DataHelm\Crawler\Output;

use DataHelm\Crawler\Scraping\ScrapedItem;
use Illuminate\Database\Eloquent\Model;

/**
 * Persists scraped items directly to an Eloquent model via upsert.
 *
 * Usage (bind in AppServiceProvider or pass directly):
 *
 *   new DatabaseSink(
 *       model:       MyAuction::class,
 *       uniqueBy:    ['link'],          // upsert key(s)
 *       updateOnly:  ['title', 'price'],// restrict which columns are updated
 *   );
 *
 * Rows are buffered and upserted in batches of $batchSize (default 500) — one
 * query per batch instead of one per item, which matters for large crawls. Set
 * $batchSize = 1 for immediate per-item writes.
 *
 * Field mapping: by default every key in ScrapedItem::toArray() is written.
 * Pass $fieldMap to rename or subset fields:
 *   ['link' => 'url', 'title' => 'name']
 *
 * @template TModel of Model
 */
class DatabaseSink implements OutputSink
{
    private int $count = 0;

    /** @var list<array<string,mixed>> Rows awaiting the next batch flush. */
    private array $buffer = [];

    /**
     * @param class-string<TModel>   $model      Eloquent model class.
     * @param list<string>           $uniqueBy   Column(s) used as the upsert key.
     * @param list<string>|null      $updateOnly Limit which columns are updated (null = all).
     * @param array<string,string>   $fieldMap   Rename scraped fields before insert.
     * @param list<string>           $exclude    Scraped fields to drop before insert.
     * @param int                    $batchSize  Rows per upsert query (>= 1).
     */
    public function __construct(
        private readonly string $model,
        private readonly array $uniqueBy = ['link'],
        private readonly ?array $updateOnly = null,
        private readonly array $fieldMap = [],
        private readonly array $exclude = [],
        private readonly int $batchSize = 500,
    ) {
    }

    public function open(string $name): void
    {
        $this->count  = 0;
        $this->buffer = [];
    }

    public function write(ScrapedItem $item): void
    {
        $row = $this->mapFields($item->toArray());

        if ($row === []) {
            return;
        }

        $this->buffer[] = $row;

        if (count($this->buffer) >= max(1, $this->batchSize)) {
            $this->flush();
        }
    }

    /**
     * Upsert the buffered rows in a single query. Columns are keyed off the first
     * row; a heterogeneous batch (differing keys) is split so each upsert sees a
     * consistent column set — the DB driver requires uniform columns per call.
     */
    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        /** @var class-string<Model> $model */
        $model = $this->model;

        // Group consecutive rows that share the same column set; upsert each group.
        $group = [];
        $signature = null;
        foreach ([...$this->buffer, null] as $row) {
            $rowSignature = $row === null ? null : implode('|', array_keys($row));
            if ($row === null || ($signature !== null && $rowSignature !== $signature)) {
                $updateColumns = $this->updateOnly
                    ?? array_keys(array_diff_key($group[0], array_flip($this->uniqueBy)));
                $model::upsert($group, $this->uniqueBy, $updateColumns);
                $this->count += count($group);
                $group = [];
            }
            if ($row !== null) {
                $group[]   = $row;
                $signature = $rowSignature;
            }
        }

        $this->buffer = [];
    }

    public function close(): string
    {
        $this->flush();

        /** @var Model $instance */
        $instance = new $this->model();

        return sprintf(
            '%d row(s) upserted into %s.%s',
            $this->count,
            $instance->getConnection()->getDatabaseName(),
            $instance->getTable(),
        );
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function mapFields(array $data): array
    {
        // Drop excluded keys.
        foreach ($this->exclude as $key) {
            unset($data[$key]);
        }

        if ($this->fieldMap === []) {
            return $data;
        }

        $mapped = [];
        foreach ($data as $key => $value) {
            $mapped[$this->fieldMap[$key] ?? $key] = $value;
        }

        return $mapped;
    }
}

<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\CrawlMode;
use DataHelm\Crawler\Blueprint\PaginationStrategy;
use DataHelm\Crawler\Blueprint\ScrapeBlueprint;

/**
 * Validates a {@see ScrapeBlueprint} for consistency and completeness.
 *
 * Produces a list of errors (hard problems that will definitely break the
 * crawl) and warnings (likely mis-configurations that may produce bad results).
 * An empty error list means the blueprint is valid.
 */
final class BlueprintValidator
{
    private const VALID_FIELD_TYPES = ['css', 'xpath', 'json'];
    private const VALID_SCHEMA_TYPES = [
        'string', 'int', 'float', 'bool',
        'string[]', 'int[]', 'float[]',
    ];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Validate the blueprint and return whether it passed (no errors).
     * Results are available via errors() and warnings() afterwards.
     */
    public function validate(ScrapeBlueprint $blueprint): bool
    {
        $this->errors   = [];
        $this->warnings = [];

        $this->checkUrl($blueprint);
        $this->checkMode($blueprint);
        $this->checkFields($blueprint);
        $this->checkDedup($blueprint);
        $this->checkPagination($blueprint);
        $this->checkItemSchema($blueprint);
        $this->checkPipelineNames($blueprint);
        $this->checkImages($blueprint);
        $this->checkRenderJs($blueprint);

        return $this->errors === [];
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    // -------------------------------------------------------------------------

    private function checkUrl(ScrapeBlueprint $blueprint): void
    {
        if ($blueprint->url === '') {
            $this->errors[] = 'url is required';
            return;
        }

        if (! filter_var($blueprint->url, FILTER_VALIDATE_URL)) {
            $this->errors[] = "url '{$blueprint->url}' is not a valid URL";
        }
    }

    private function checkMode(ScrapeBlueprint $blueprint): void
    {
        if ($blueprint->mode === CrawlMode::HTML) {
            if ($blueprint->itemSelector === '') {
                $this->errors[] = 'item_selector is required in HTML mode';
            }

            if ($blueprint->fields === []) {
                $this->warnings[] = 'No fields defined — crawl will produce empty items';
            }
        }

        if ($blueprint->mode === CrawlMode::API) {
            if ($blueprint->api === null) {
                $this->errors[] = 'api config is required when mode = api';
            } elseif ($blueprint->api->endpoint === '') {
                $this->errors[] = 'api.endpoint is required when mode = api';
            }
        }
    }

    private function checkFields(ScrapeBlueprint $blueprint): void
    {
        $fieldNames = [];

        foreach ($blueprint->fields as $i => $field) {
            $label = "fields[{$i}] (name={$field->name})";

            if ($field->name === '') {
                $this->errors[] = "{$label}: name is required";
            } elseif (isset($fieldNames[$field->name])) {
                $this->warnings[] = "Duplicate field name '{$field->name}' — second definition wins";
            } else {
                $fieldNames[$field->name] = true;
            }

            if ($field->type !== '' && ! in_array($field->type, self::VALID_FIELD_TYPES, true)) {
                $this->errors[] = "{$label}: type '{$field->type}' must be one of: " . implode(', ', self::VALID_FIELD_TYPES);
            }

            if ($field->css === '' && $field->type !== 'json') {
                $this->warnings[] = "{$label}: css/xpath selector is empty (field will always extract null)";
            }
        }

        // Detail fields
        foreach ($blueprint->detailFields as $i => $field) {
            if ($field->name === '') {
                $this->errors[] = "detail_fields[{$i}]: name is required";
            }
        }

        if ($blueprint->scrapeDetail && $blueprint->detailLinkField === null) {
            $this->errors[] = 'detail_link_field is required when scrape_detail = true';
        }

        if ($blueprint->scrapeDetail && $blueprint->detailLinkField !== null && $fieldNames !== []) {
            if (! isset($fieldNames[$blueprint->detailLinkField])) {
                $this->warnings[] = "detail_link_field '{$blueprint->detailLinkField}' is not in fields — detail pages cannot be followed";
            }
        }
    }

    private function checkDedup(ScrapeBlueprint $blueprint): void
    {
        if (! $blueprint->dedup->enabled) {
            return;
        }

        $fieldNames = array_map(static fn ($f) => $f->name, $blueprint->fields);
        $detailNames = array_map(static fn ($f) => $f->name, $blueprint->detailFields);

        if (! in_array($blueprint->dedup->keyField, $fieldNames, true)
            && ! in_array($blueprint->dedup->keyField, $detailNames, true)
            && $blueprint->mode !== CrawlMode::API
        ) {
            $this->warnings[] = "dedup.key_field '{$blueprint->dedup->keyField}' is not in fields or detail_fields — dedup may not work correctly";
        }
    }

    private function checkPagination(ScrapeBlueprint $blueprint): void
    {
        $strategy = $blueprint->pagination->strategy;

        if ($strategy === PaginationStrategy::INFINITE_SCROLL && $blueprint->infiniteScroll === null) {
            $this->errors[] = 'infinite_scroll config is required when pagination.strategy = infinite_scroll';
        }

        if ($strategy === PaginationStrategy::NEXT_LINK && $blueprint->pagination->nextCss === '' && $blueprint->pagination->nextXpath === '') {
            $this->warnings[] = 'pagination.strategy = next_link but no next_css or next_xpath defined — only the first page will be scraped';
        }

        if ($strategy === PaginationStrategy::LINK_LIST && $blueprint->pagination->listCss === '') {
            $this->warnings[] = 'pagination.strategy = link_list but no list_css defined — only the first page will be scraped';
        }
    }

    private function checkItemSchema(ScrapeBlueprint $blueprint): void
    {
        foreach ($blueprint->itemSchema as $field => $type) {
            if (! in_array($type, self::VALID_SCHEMA_TYPES, true)) {
                $this->errors[] = "item_schema['{$field}']: unknown type '{$type}'. Valid types: " . implode(', ', self::VALID_SCHEMA_TYPES);
            }
        }
    }

    private function checkPipelineNames(ScrapeBlueprint $blueprint): void
    {
        if ($blueprint->pipelineNames === []) {
            return;
        }

        $registry = (array) config('crawler.pipeline_registry', []);

        foreach ($blueprint->pipelineNames as $name) {
            if (! isset($registry[$name])) {
                $this->warnings[] = "pipeline_names: '{$name}' not found in config('crawler.pipeline_registry') — will be skipped at runtime";
            }
        }
    }

    private function checkImages(ScrapeBlueprint $blueprint): void
    {
        if (! $blueprint->getAllImages && ! $blueprint->getPrimaryImage && ! $blueprint->getGalleryImages) {
            return;
        }

        if ($blueprint->imageResize->enabled) {
            if ($blueprint->imageResize->width === null && $blueprint->imageResize->height === null) {
                $this->warnings[] = 'image_resize.enabled = true but neither width nor height is set — images will not be resized';
            }
        }
    }

    private function checkRenderJs(ScrapeBlueprint $blueprint): void
    {
        if ($blueprint->httpConfig->renderJs) {
            $transport = config('crawler.transport', 'guzzle');
            if ($transport !== 'browser') {
                $this->warnings[] = 'http_config.render_js = true but config(\'crawler.transport\') is not \'browser\' — bind a BrowserHttpClient and set the transport to enable JS rendering';
            }
        }
    }
}

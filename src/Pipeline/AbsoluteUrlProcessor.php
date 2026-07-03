<?php

namespace DataHelm\Crawler\Pipeline;

use DataHelm\Crawler\Dom\Url;
use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Resolves URL-bearing fields (link/image/…) to absolute URLs against the page
 * the item was scraped from.
 *
 * Some JSON APIs return bare image filenames (e.g. "uuid.jpg"). When the active
 * preset defines an image_path_prefix (see config/crawler.php) those filenames
 * are resolved under that prefix before being made absolute.
 */
final class AbsoluteUrlProcessor implements ItemProcessor
{
    private const URL_FIELDS = ['link', 'image', 'images', 'gallery_images', 'all_images', 'url', 'href', 'src', 'detail_image'];

    public function __construct(private readonly ?string $imagePathPrefix = null)
    {
    }

    public function process(ScrapedItem $item, string $pageUrl): ScrapedItem
    {
        foreach (self::URL_FIELDS as $field) {
            $value = $item->get($field);

            if (is_string($value) && $value !== '') {
                $item->set($field, $this->resolveUrl($value, $pageUrl, $field));
            } elseif (is_array($value)) {
                $item->set($field, array_map(
                    fn ($entry) => is_string($entry) && $entry !== ''
                        ? $this->resolveUrl($entry, $pageUrl, $field)
                        : $entry,
                    $value,
                ));
            }
        }

        return $item;
    }

    /**
     * Turn a relative or bare-filename URL into an absolute one.
     *
     * Bare image filenames (e.g. "uuid.jpg" from JSON APIs) are resolved under
     * the configured image_path_prefix when one is set.
     */
    private function resolveUrl(string $value, string $pageUrl, string $field = ''): string
    {
        // API relative paths (e.g. "lote/foo/3354/") are site-root paths, not
        // relative to the listing page directory.
        if (
            $field === 'link'
            && ! preg_match('#^https?://#i', $value)
            && ! str_starts_with($value, '/')
            && ! str_starts_with($value, '//')
        ) {
            $value = '/' . ltrim($value, '/');
        }

        // Bare image filename from APIs → resolve under the preset prefix.
        if (
            $this->imagePathPrefix !== null
            && ! preg_match('#^https?://#i', $value)
            && ! str_starts_with($value, '/')
            && preg_match('/\.(jpe?g|png|gif|webp|svg)$/i', $value)
        ) {
            $value = '/' . trim($this->imagePathPrefix, '/') . '/' . $value;
        }

        return Url::absolute($pageUrl, $value) ?? $value;
    }
}

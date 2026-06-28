<?php

namespace DataHelm\Crawler\Media;

use DataHelm\Crawler\Blueprint\ScrapeBlueprint;
use DataHelm\Crawler\Detection\ImageQualityHeuristic;
use DataHelm\Crawler\Scraping\ScrapedItem;

/**
 * Resolves image URLs for a scraped item into the output JSON. It does NOT
 * download anything — the responsibility of actually storing images lives in
 * the robot's per-item callback (see the generated robot's `handle()`), where
 * you inject {@see ImageStore} and choose the disk/folder yourself.
 *
 * Output fields (controlled by blueprint flags):
 *   - "gallery_images": detail-page gallery URLs ({@see ScrapeBlueprint::$getGalleryImages})
 *   - "primary_image":  the single most-relevant URL ({@see ScrapeBlueprint::$getPrimaryImage})
 *   - "all_images":     deduplicated union of list thumbnail + gallery + primary
 *                       ({@see ScrapeBlueprint::$getAllImages})
 *
 * The list-card "image" field is populated by the blueprint's field selectors;
 * this class only shapes the convenience image fields above.
 *
 * Icon/badge/decoration URLs are down-scored by {@see ImageQualityHeuristic}
 * so a real photo wins over a small badge even when the badge appears first.
 *
 * Shared by both the HTML crawl engine (via ItemSink) and the API crawler.
 */
final class ItemImageResolver
{
    /**
     * Shape image fields on the item according to the blueprint's image flags.
     */
    public function enrich(ScrapedItem $item, ScrapeBlueprint $blueprint): void
    {
        $wantGallery = $blueprint->getGalleryImages || $blueprint->getAllImages;
        $wantPrimary = $blueprint->getPrimaryImage || $blueprint->getAllImages;

        if ($wantGallery) {
            $this->normalizeGalleryField($item);
        }

        if ($wantPrimary) {
            $this->resolve($item);
        } else {
            $item->remove('primary_image');
        }

        if ($blueprint->getAllImages) {
            $all = $this->collectAllImages($item);
            if ($all !== []) {
                $item->set('all_images', $all);
            } else {
                $item->remove('all_images');
            }
        } else {
            $item->remove('all_images');
        }
    }

    /**
     * Resolve and set the "primary_image" URL on the item (no I/O).
     */
    public function resolve(ScrapedItem $item): void
    {
        $primaryUrl = $this->resolvePrimaryUrl($item);
        if ($primaryUrl !== null) {
            $item->set('primary_image', $primaryUrl);
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Migrate legacy "images" to "gallery_images" when gallery mode is active.
     */
    private function normalizeGalleryField(ScrapedItem $item): void
    {
        if ($item->get('gallery_images') === null && is_array($item->get('images'))) {
            $item->set('gallery_images', $item->get('images'));
        }

        $item->remove('images');
    }

    /**
     * Determine the primary image URL for this item.
     *
     * Priority:
     *   1. "image" field (list-card thumbnail) — single string or array.
     *      The best-scored non-icon URL is chosen.
     *   2. "gallery_images" / legacy "images" — array fallback.
     */
    private function resolvePrimaryUrl(ScrapedItem $item): ?string
    {
        // Gather list-card image candidate(s).
        $listImages = [];
        $image = $item->get('image');
        if (is_string($image) && $image !== '') {
            $listImages[] = $image;
        } elseif (is_array($image)) {
            $listImages = array_filter($image, static fn ($v) => is_string($v) && $v !== '');
        }

        $best = ImageQualityHeuristic::bestUrl(array_values($listImages));
        if ($best !== null && ImageQualityHeuristic::scoreUrl($best) >= -50) {
            return $best;
        }

        // Fallback: first real photo from the detail-page gallery.
        $galleryImages = $this->galleryUrls($item);
        if ($galleryImages !== []) {
            $filtered = array_values(array_filter(
                $galleryImages,
                static fn ($v) => ImageQualityHeuristic::scoreUrl($v) >= -50,
            ));

            if ($filtered !== []) {
                return $filtered[0];
            }

            return $galleryImages[0];
        }

        // Last resort: return the list image even if it looks like an icon.
        return $best;
    }

    /**
     * @return list<string>
     */
    private function galleryUrls(ScrapedItem $item): array
    {
        foreach (['gallery_images', 'images'] as $field) {
            $val = $item->get($field);
            if (is_array($val)) {
                return array_values(array_filter($val, static fn ($v) => is_string($v) && $v !== ''));
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function collectAllImages(ScrapedItem $item): array
    {
        $urls = [];

        foreach (['image', 'primary_image'] as $field) {
            $val = $item->get($field);
            if (is_string($val) && $val !== '') {
                $urls[] = $val;
            } elseif (is_array($val)) {
                foreach ($val as $u) {
                    if (is_string($u) && $u !== '') {
                        $urls[] = $u;
                    }
                }
            }
        }

        foreach ($this->galleryUrls($item) as $u) {
            $urls[] = $u;
        }

        return array_values(array_unique($urls));
    }
}

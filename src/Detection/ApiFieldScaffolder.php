<?php

namespace DataHelm\Crawler\Detection;

use DataHelm\Crawler\Blueprint\FieldSelector;

/**
 * Turns a sample JSON record into a set of `type: json` field selectors so the
 * generated blueprint lists every scalar key as a candidate the user can keep
 * or prune (mirroring the HTML generator's generous field suggestions).
 *
 * Keys that look like an image or a link/id are additionally surfaced under the
 * conventional "image" / "images" / "link" names the rest of the pipeline
 * understands (so deduplication and primary-image selection work out of the box).
 */
final class ApiFieldScaffolder
{
    /** @var list<string> */
    private array $imageHints;

    /** @var list<string> */
    private array $linkHints;

    /**
     * Hint lists come from the active preset (see config/crawler.php) so the
     * scaffolder carries no language/vertical assumptions of its own.
     *
     * @param list<string> $imageHints Substrings that mark a key as an image.
     * @param list<string> $linkHints  Substrings that mark a key as a link.
     */
    public function __construct(
        array $imageHints = ['image', 'img', 'photo', 'thumb', 'picture'],
        array $linkHints = ['url', 'link', 'href', 'permalink', 'slug'],
    ) {
        $this->imageHints = $imageHints !== [] ? array_values($imageHints) : ['image', 'img', 'photo'];
        $this->linkHints  = $linkHints !== [] ? array_values($linkHints) : ['url', 'link', 'href'];
    }

    /**
     * @param array<string,mixed> $sample
     *
     * @return list<FieldSelector>
     */
    public function scaffold(array $sample): array
    {
        $fields   = [];
        $byName   = [];
        $hasImage = false;
        $hasLink  = false;

        foreach ($sample as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            // Only scalar values become direct fields; nested objects are left
            // for the user to address with an explicit dot-path.
            if (is_array($value) && ! array_is_list($value)) {
                continue;
            }

            $name = $this->fieldName($key);
            if (isset($byName[$name])) {
                continue;
            }

            $multiple = is_array($value);
            $fields[] = new FieldSelector(name: $name, css: $key, multiple: $multiple, type: 'json');
            $byName[$name] = true;

            if (! $hasImage && $this->matchesHint($key, $this->imageHints)) {
                // An array of photo URLs → "gallery_images" (also feeds the
                // primary-image picker); a single URL → "image" thumbnail.
                $imageField = $multiple ? 'gallery_images' : 'image';
                $fields[]   = new FieldSelector(name: $imageField, css: $key, multiple: $multiple, type: 'json');
                $hasImage   = true;
            }
            if (! $hasLink && $this->matchesHint($key, $this->linkHints) && ! $multiple) {
                $fields[] = new FieldSelector(name: 'link', css: $key, type: 'json');
                $hasLink = true;
            }
        }

        return $fields;
    }

    private function fieldName(string $key): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));

        return (string) preg_replace('/[^a-z0-9_]+/', '_', $snake);
    }

    /**
     * @param list<string> $hints
     */
    private function matchesHint(string $key, array $hints): bool
    {
        $lower = strtolower($key);
        foreach ($hints as $hint) {
            if (str_contains($lower, $hint)) {
                return true;
            }
        }

        return false;
    }
}

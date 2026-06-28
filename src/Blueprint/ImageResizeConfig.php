<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * Configuration for optional image resizing after download.
 *
 * fit modes
 * ---------
 *   contain  — scale to fit inside width × height, pad remaining area with $background (letterbox)
 *   cover    — scale to fill width × height, crop from center (no empty space)
 *   stretch  — force exact width × height, ignoring aspect ratio
 *   max      — scale down only to fit within width × height, keep aspect ratio, no padding
 *
 * format
 * ------
 *   null  → keep the original format
 *   jpg   → convert to JPEG
 *   png   → convert to PNG
 *   webp  → convert to WebP
 *   gif   → convert to GIF
 *
 * quality applies to JPEG and WebP (1–100). PNG compression is derived as (100 - quality) / 10.
 */
final class ImageResizeConfig
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly string $fit = 'contain',
        public readonly int $quality = 85,
        public readonly ?string $format = null,
        public readonly string $background = '#ffffff',
    ) {
    }

    public static function disabled(): self
    {
        return new self(enabled: false);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            enabled:    (bool) ($data['enabled'] ?? false),
            width:      isset($data['width'])  && $data['width']  !== null ? (int) $data['width']  : null,
            height:     isset($data['height']) && $data['height'] !== null ? (int) $data['height'] : null,
            fit:        (string) ($data['fit'] ?? 'contain'),
            quality:    max(1, min(100, (int) ($data['quality'] ?? 85))),
            format:     isset($data['format']) && $data['format'] !== null && $data['format'] !== '' ? strtolower((string) $data['format']) : null,
            background: (string) ($data['background'] ?? '#ffffff'),
        );
    }

    public function toArray(): array
    {
        return [
            'enabled'    => $this->enabled,
            'width'      => $this->width,
            'height'     => $this->height,
            'fit'        => $this->fit,
            'quality'    => $this->quality,
            'format'     => $this->format,
            'background' => $this->background,
        ];
    }
}

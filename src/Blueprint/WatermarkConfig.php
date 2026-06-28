<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * Text watermark overlaid on every saved image using PHP GD.
 *
 * position  — one of: top-left, top-right, bottom-left, bottom-right, center.
 * font_size — maps to a GD built-in font (1–5): ≤10→1, ≤12→2, ≤14→3, ≤16→4, >16→5.
 * color     — hex colour for the text (e.g. "#ffffff").
 * opacity   — 0 (fully transparent) to 100 (fully opaque).
 */
final class WatermarkConfig
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $text = '',
        public readonly string $position = 'bottom-right',
        public readonly int $fontSize = 14,
        public readonly string $color = '#ffffff',
        public readonly int $opacity = 70,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            enabled:  (bool) ($data['enabled'] ?? false),
            text:     (string) ($data['text'] ?? ''),
            position: (string) ($data['position'] ?? 'bottom-right'),
            fontSize: max(1, (int) ($data['font_size'] ?? 14)),
            color:    (string) ($data['color'] ?? '#ffffff'),
            opacity:  max(0, min(100, (int) ($data['opacity'] ?? 70))),
        );
    }

    public function toArray(): array
    {
        return [
            'enabled'   => $this->enabled,
            'text'      => $this->text,
            'position'  => $this->position,
            'font_size' => $this->fontSize,
            'color'     => $this->color,
            'opacity'   => $this->opacity,
        ];
    }
}

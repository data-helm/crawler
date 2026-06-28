<?php

namespace DataHelm\Crawler\Media;

use DataHelm\Crawler\Blueprint\ImageResizeConfig;
use DataHelm\Crawler\Blueprint\WatermarkConfig;
use DataHelm\Crawler\Http\HttpClient;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads an image, optionally resizes/converts it via GD, then stores it.
 *
 * "storage" (and "local") write directly under storage/app/<folder> — next to
 * the scraped JSON. Any other value is treated as a configured Laravel
 * filesystem disk (public, s3, gcs, azure, spaces, …) and written via the
 * Storage facade, so cloud targets need only a disk entry in config/filesystems.
 *
 * Resize fit modes (see ImageResizeConfig):
 *   contain  — scale to fit inside width × height, pad with background colour
 *   cover    — scale to fill width × height, crop from centre
 *   stretch  — force exact width × height, ignoring aspect ratio
 *   max      — scale down only, keep aspect ratio, no padding
 */
final class ImageStore
{
    private const LOCAL_DISKS = ['storage', 'local'];

    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * @return string|null Stored path/key, or null on failure.
     */
    public function store(
        string $url,
        string $disk,
        string $folder,
        bool $hashName,
        ?ImageResizeConfig $resize = null,
        ?WatermarkConfig $watermark = null,
    ): ?string {
        try {
            $bytes = $this->http->get($url);
        } catch (\Throwable) {
            return null;
        }

        if ($bytes === '') {
            return null;
        }

        if ($resize !== null && $resize->enabled) {
            $resized = $this->applyResize($bytes, $resize);
            if ($resized !== '') {
                $bytes = $resized;
            }
        }

        if ($watermark !== null && $watermark->enabled && $watermark->text !== '') {
            $marked = $this->applyWatermark($bytes, $watermark);
            if ($marked !== '') {
                $bytes = $marked;
            }
        }

        $path = trim($folder, '/') . '/' . $this->fileName($url, $bytes, $hashName, $resize);

        try {
            if (in_array($disk, self::LOCAL_DISKS, true)) {
                $full = storage_path('app/' . $path);
                $dir  = dirname($full);
                if (! is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                file_put_contents($full, $bytes);
            } else {
                Storage::disk($disk)->put($path, $bytes);
            }
        } catch (\Throwable) {
            return null;
        }

        return $path;
    }

    // -------------------------------------------------------------------------
    // Resize
    // -------------------------------------------------------------------------

    private function applyResize(string $bytes, ImageResizeConfig $config): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return '';
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return '';
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $targetW = $config->width  ?? $srcW;
        $targetH = $config->height ?? $srcH;

        $format = $config->format ?? $this->detectFormat($bytes);

        $canvas = match ($config->fit) {
            'stretch' => $this->fitStretch($src, $srcW, $srcH, $targetW, $targetH, $format, $config->background),
            'cover'   => $this->fitCover($src, $srcW, $srcH, $targetW, $targetH, $format, $config->background),
            'max'     => $this->fitMax($src, $srcW, $srcH, $targetW, $targetH, $format, $config->background),
            default   => $this->fitContain($src, $srcW, $srcH, $targetW, $targetH, $format, $config->background),
        };

        imagedestroy($src);

        $result = $this->encodeImage($canvas, $format, $config->quality);

        return $result;
    }

    /**
     * Force exact width × height, ignoring aspect ratio.
     */
    private function fitStretch(\GdImage $src, int $srcW, int $srcH, int $w, int $h, string $format, string $bg): \GdImage
    {
        $canvas = $this->createCanvas($w, $h, $format, $bg);
        imagecopyresampled($canvas, $src, 0, 0, 0, 0, $w, $h, $srcW, $srcH);

        return $canvas;
    }

    /**
     * Scale to fill width × height, crop from centre.
     */
    private function fitCover(\GdImage $src, int $srcW, int $srcH, int $w, int $h, string $format, string $bg): \GdImage
    {
        $ratio    = max($w / $srcW, $h / $srcH);
        $cropW    = (int) round($w / $ratio);
        $cropH    = (int) round($h / $ratio);
        $cropX    = (int) round(($srcW - $cropW) / 2);
        $cropY    = (int) round(($srcH - $cropH) / 2);

        $canvas = $this->createCanvas($w, $h, $format, $bg);
        imagecopyresampled($canvas, $src, 0, 0, $cropX, $cropY, $w, $h, $cropW, $cropH);

        return $canvas;
    }

    /**
     * Scale down only to fit within width × height, keep aspect ratio, no padding.
     */
    private function fitMax(\GdImage $src, int $srcW, int $srcH, int $w, int $h, string $format, string $bg): \GdImage
    {
        $ratio = min(1.0, $w / $srcW, $h / $srcH);
        $dstW  = (int) round($srcW * $ratio);
        $dstH  = (int) round($srcH * $ratio);

        $canvas = $this->createCanvas($dstW, $dstH, $format, $bg);
        imagecopyresampled($canvas, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        return $canvas;
    }

    /**
     * Scale to fit inside width × height, pad remaining area with background colour.
     */
    private function fitContain(\GdImage $src, int $srcW, int $srcH, int $w, int $h, string $format, string $bg): \GdImage
    {
        $ratio   = min($w / $srcW, $h / $srcH);
        $dstW    = (int) round($srcW * $ratio);
        $dstH    = (int) round($srcH * $ratio);
        $offsetX = (int) round(($w - $dstW) / 2);
        $offsetY = (int) round(($h - $dstH) / 2);

        $canvas = $this->createCanvas($w, $h, $format, $bg);
        imagecopyresampled($canvas, $src, $offsetX, $offsetY, 0, 0, $dstW, $dstH, $srcW, $srcH);

        return $canvas;
    }

    // -------------------------------------------------------------------------
    // GD helpers
    // -------------------------------------------------------------------------

    private function createCanvas(int $w, int $h, string $format, string $background): \GdImage
    {
        $canvas = imagecreatetruecolor($w, $h);

        if (in_array($format, ['png', 'gif'], true)) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);
            imagealphablending($canvas, true);
        } else {
            [$r, $g, $b] = $this->hexToRgb($background);
            $bg = imagecolorallocate($canvas, $r, $g, $b);
            imagefill($canvas, 0, 0, $bg);
        }

        return $canvas;
    }

    private function encodeImage(\GdImage $image, string $format, int $quality): string
    {
        ob_start();

        match ($format) {
            'png'  => imagepng($image, null, (int) round((100 - $quality) / 11)),
            'gif'  => imagegif($image),
            'webp' => imagewebp($image, null, $quality),
            default => imagejpeg($image, null, $quality),
        };

        $result = ob_get_clean();
        imagedestroy($image);

        return is_string($result) ? $result : '';
    }

    /**
     * @return array{int, int, int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    // -------------------------------------------------------------------------
    // Watermark
    // -------------------------------------------------------------------------

    private function applyWatermark(string $bytes, WatermarkConfig $config): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return '';
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return '';
        }

        $w      = imagesx($img);
        $h      = imagesy($img);
        $gdFont = $this->gdFont($config->fontSize);
        $charW  = imagefontwidth($gdFont);
        $charH  = imagefontheight($gdFont);
        $textW  = $charW * strlen($config->text);
        $pad    = 10;

        [$r, $g, $b] = $this->hexToRgb($config->color);
        // GD alpha: 0 = opaque, 127 = fully transparent
        $alpha = (int) round((100 - $config->opacity) * 127 / 100);
        $color = imagecolorallocatealpha($img, $r, $g, $b, $alpha);

        [$x, $y] = match ($config->position) {
            'top-left'    => [$pad, $pad],
            'top-right'   => [$w - $textW - $pad, $pad],
            'bottom-left' => [$pad, $h - $charH - $pad],
            'center'      => [(int) (($w - $textW) / 2), (int) (($h - $charH) / 2)],
            default       => [$w - $textW - $pad, $h - $charH - $pad], // bottom-right
        };

        imagestring($img, $gdFont, max(0, $x), max(0, $y), $config->text, $color);

        $format = $this->detectFormat($bytes);
        $result = $this->encodeImage($img, $format, 85);

        return $result;
    }

    /**
     * Map a font_size value to a GD built-in font number (1–5).
     */
    private function gdFont(int $fontSize): int
    {
        return match (true) {
            $fontSize <= 10 => 1,
            $fontSize <= 12 => 2,
            $fontSize <= 14 => 3,
            $fontSize <= 16 => 4,
            default         => 5,
        };
    }

    // -------------------------------------------------------------------------
    // File naming
    // -------------------------------------------------------------------------

    private function fileName(string $url, string $bytes, bool $hashName, ?ImageResizeConfig $resize): string
    {
        $extension = $resize?->format ?? $this->extensionFromBytes($url, $bytes);
        $suffix    = $extension !== '' ? '.' . $extension : '';

        if ($hashName) {
            return sha1($bytes) . $suffix;
        }

        $base = basename((string) parse_url($url, PHP_URL_PATH));
        $base = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $base);

        if ($base === '' || $base === '_') {
            return sha1($url) . $suffix;
        }

        // If format conversion is requested, replace the original extension.
        if ($resize?->format !== null) {
            $base = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $base) ?? $base;

            return $base . $suffix;
        }

        return $base;
    }

    private function extensionFromBytes(string $url, string $bytes): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (preg_match('/^[a-z0-9]{2,5}$/', $extension)) {
            return $extension;
        }

        return $this->detectFormat($bytes);
    }

    private function detectFormat(string $bytes): string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG")                                             => 'png',
            str_starts_with($bytes, 'GIF8')                                                => 'gif',
            str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 12), 'WEBP') => 'webp',
            default                                                                         => 'jpg',
        };
    }
}

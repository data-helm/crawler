<?php

namespace DataHelm\Crawler\Media;

use DataHelm\Crawler\Http\HttpClient;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads an image and stores it on a disk.
 *
 * "storage" (and "local") write directly under storage/app/<folder> — next to
 * the scraped JSON. Any other value is treated as a configured Laravel
 * filesystem disk (public, s3, gcs, azure, spaces, …) and written via the
 * Storage facade, so cloud targets need only a disk entry in config/filesystems.
 *
 * Image processing (resize, watermark, format conversion) is intentionally NOT
 * handled here — it is application logic. Do it in your robot's per-item
 * callback (the `processImage()` hook in generated robots), e.g. with
 * Intervention Image, where you have the full imaging API.
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
    ): ?string {
        try {
            $bytes = $this->http->get($url);
        } catch (\Throwable) {
            return null;
        }

        if ($bytes === '') {
            return null;
        }

        $path = trim($folder, '/') . '/' . $this->fileName($url, $bytes, $hashName);

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
    // File naming
    // -------------------------------------------------------------------------

    private function fileName(string $url, string $bytes, bool $hashName): string
    {
        $extension = $this->extensionFromBytes($url, $bytes);
        $suffix    = $extension !== '' ? '.' . $extension : '';

        if ($hashName) {
            return sha1($bytes) . $suffix;
        }

        $base = basename((string) parse_url($url, PHP_URL_PATH));
        $base = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $base);

        if ($base === '' || $base === '_') {
            return sha1($url) . $suffix;
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

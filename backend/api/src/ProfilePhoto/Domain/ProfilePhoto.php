<?php

declare(strict_types=1);

namespace App\ProfilePhoto\Domain;

/**
 * Profile photo already validated and normalized: centered square crop, 256 px, WebP.
 * Re-encoding drops EXIF (location, camera) and anything that is not the picture itself.
 */
final readonly class ProfilePhoto
{
    public const string MIME = 'image/webp';
    public const int MAX_UPLOAD_BYTES = 5_242_880;
    private const int SIDE_PX = 256;
    /** Larger images are not even decoded (decompression bomb). */
    private const int MAX_SOURCE_PX = 6000;
    private const int QUALITY = 82;

    private function __construct(public string $bytes)
    {
    }

    /** null when the bytes are not an image GD can decode. */
    public static function fromUpload(string $bytes): ?self
    {
        $info = @getimagesizefromstring($bytes);
        if (false === $info || $info[0] <= 0 || $info[1] <= 0 || $info[0] > self::MAX_SOURCE_PX || $info[1] > self::MAX_SOURCE_PX) {
            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if (false === $source) {
            return null;
        }
        $side = min(imagesx($source), imagesy($source));
        $target = imagecreatetruecolor(self::SIDE_PX, self::SIDE_PX);
        if (false === $target) {
            return null;
        }
        // Transparent areas become white instead of black.
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, intdiv(imagesx($source) - $side, 2), intdiv(imagesy($source) - $side, 2),
            self::SIDE_PX, self::SIDE_PX, $side, $side);
        ob_start();
        imagewebp($target, null, self::QUALITY);

        return new self((string) ob_get_clean());
    }

    /** Address of the photo; the version changes it on every new photo, so browsers may cache it for good. */
    public static function url(string $path, mixed $updatedAt): ?string
    {
        return null === $updatedAt ? null : $path.'?v='.substr(hash('xxh3', (string) $updatedAt), 0, 12);
    }
}

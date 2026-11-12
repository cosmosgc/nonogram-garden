<?php

namespace App\Services\Nonogram;

use Illuminate\Http\UploadedFile;

class PuzzleImageProcessor
{
    private const int MAX_FILE_BYTES = 10 * 1024 * 1024;

    private const int MAX_PIXELS = 4_000_000;

    /** @var array<string, array{type: int, extension: string}> */
    private const array SOURCE_TYPES = [
        'image/jpeg' => ['type' => IMAGETYPE_JPEG, 'extension' => 'jpg'],
        'image/png' => ['type' => IMAGETYPE_PNG, 'extension' => 'png'],
        'image/webp' => ['type' => IMAGETYPE_WEBP, 'extension' => 'webp'],
    ];

    public function supportsSource(UploadedFile $file): bool
    {
        return $this->supports($file, self::SOURCE_TYPES);
    }

    public function supportsRevealCopy(UploadedFile $file): bool
    {
        return $this->supports($file, [
            'image/png' => ['type' => IMAGETYPE_PNG, 'extension' => 'png'],
        ]);
    }

    public function sourceExtension(UploadedFile $file): string
    {
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());

        return self::SOURCE_TYPES[$mimeType]['extension'];
    }

    public function toCleanPng(UploadedFile $file): string
    {
        if (! $this->supportsRevealCopy($file)) {
            throw new \RuntimeException('The reveal image is not a supported PNG.');
        }

        $contents = file_get_contents($file->getPathname());
        if (! is_string($contents)) {
            throw new \RuntimeException('The reveal image could not be read.');
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            throw new \RuntimeException('The reveal image could not be decoded.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();

        try {
            if (! imagepng($image, null, 9)) {
                throw new \RuntimeException('The reveal image could not be re-encoded.');
            }

            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }

        if (! is_string($contents)) {
            throw new \RuntimeException('The reveal image could not be re-encoded.');
        }

        return $contents;
    }

    /** @param array<string, array{type: int, extension: string}> $allowedTypes */
    private function supports(UploadedFile $file, array $allowedTypes): bool
    {
        if ($file->getSize() > self::MAX_FILE_BYTES) {
            return false;
        }

        $image = @getimagesize($file->getPathname());
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $type = $allowedTypes[$mimeType] ?? null;

        $validExtensions = $mimeType === 'image/jpeg' ? ['jpg', 'jpeg'] : [$type['extension'] ?? ''];
        if (! is_array($image) || $type === null || $image[2] !== $type['type'] || ! in_array($extension, $validExtensions, true)) {
            return false;
        }

        [$width, $height] = $image;

        return $width > 0 && $height > 0 && $width * $height <= self::MAX_PIXELS;
    }
}

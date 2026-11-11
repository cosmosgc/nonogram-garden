<?php

namespace App\Services\Profile;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class AvatarImageProcessor
{
    private const int MAX_DIMENSION = 1024;

    private const int MAX_PIXELS = 1_048_576;

    /**
     * @var array<string, int>
     */
    private const array MIME_TYPES = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
    ];

    public function supports(UploadedFile $file): bool
    {
        $image = @getimagesize($file->getPathname());
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $extensionTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];

        if (! is_array($image) || ! isset(self::MIME_TYPES[$mimeType]) || $image[2] !== self::MIME_TYPES[$mimeType]
            || ($extensionTypes[$extension] ?? null) !== $mimeType) {
            return false;
        }

        [$width, $height] = $image;

        return $width > 0
            && $height > 0
            && $width <= self::MAX_DIMENSION
            && $height <= self::MAX_DIMENSION
            && $width * $height <= self::MAX_PIXELS;
    }

    public function toCleanPng(UploadedFile $file): string
    {
        if (! $this->supports($file)) {
            throw new RuntimeException('The uploaded avatar is not a supported image.');
        }

        $image = @imagecreatefromstring($file->get());

        if ($image === false) {
            throw new RuntimeException('The uploaded avatar could not be decoded.');
        }

        ob_start();

        try {
            if (! imagepng($image, null, 9)) {
                throw new RuntimeException('The uploaded avatar could not be re-encoded.');
            }

            $png = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }

        if (! is_string($png)) {
            throw new RuntimeException('The uploaded avatar could not be re-encoded.');
        }

        return $png;
    }
}

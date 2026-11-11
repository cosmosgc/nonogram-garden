<?php

namespace App\Services\Nonogram;

use InvalidArgumentException;

/** Versioned, row-major monochrome grid value object. */
final readonly class Grid
{
    public const FORMAT_VERSION = 1;

    public const MAX_DIMENSION = 30;

    public const MAX_CELLS = 900;

    private function __construct(
        public int $width,
        public int $height,
        public string $cells,
    ) {}

    /** @param array{version?: int, width: int, height: int, cells: string} $document */
    public static function fromArray(array $document): self
    {
        $version = $document['version'] ?? self::FORMAT_VERSION;
        $width = $document['width'] ?? null;
        $height = $document['height'] ?? null;
        $cells = $document['cells'] ?? null;

        if ($version !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('Unsupported grid format version.');
        }
        if (! is_int($width) || ! is_int($height) || $width < 1 || $height < 1
            || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
            || $width * $height > self::MAX_CELLS) {
            throw new InvalidArgumentException('Grid dimensions must be integers from 1 to 30.');
        }
        if (! is_string($cells) || strlen($cells) !== $width * $height || preg_match('/[^01]/', $cells)) {
            throw new InvalidArgumentException('Grid cells must be a row-major binary string matching width × height.');
        }

        return new self($width, $height, $cells);
    }

    /** @return array{version: int, width: int, height: int, cells: string} */
    public function toArray(): array
    {
        return ['version' => self::FORMAT_VERSION, 'width' => $this->width, 'height' => $this->height, 'cells' => $this->cells];
    }

    /** @return array{rows: list<list<int>>, columns: list<list<int>>} */
    public function clues(): array
    {
        $rows = [];
        for ($y = 0; $y < $this->height; $y++) {
            $rows[] = self::lineClues(substr($this->cells, $y * $this->width, $this->width));
        }

        $columns = [];
        for ($x = 0; $x < $this->width; $x++) {
            $line = '';
            for ($y = 0; $y < $this->height; $y++) {
                $line .= $this->cells[$y * $this->width + $x];
            }
            $columns[] = self::lineClues($line);
        }

        return ['rows' => $rows, 'columns' => $columns];
    }

    public function hash(): string
    {
        return hash('sha256', self::FORMAT_VERSION.':'.$this->width.':'.$this->height.':'.$this->cells);
    }

    /** @return list<int> */
    private static function lineClues(string $line): array
    {
        preg_match_all('/1+/', $line, $matches);
        $runs = array_map('strlen', $matches[0]);

        return $runs === [] ? [0] : $runs;
    }
}

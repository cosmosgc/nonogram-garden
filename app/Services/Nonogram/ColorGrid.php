<?php

namespace App\Services\Nonogram;

use InvalidArgumentException;

/**
 * Versioned, palette-aware Color Picross grid value object.
 *
 * Cells are single digits: "0" is empty and "1"–"8" reference the 1-based
 * position of a palette entry. Clues are lists of [run_length, color_id] pairs.
 */
final readonly class ColorGrid
{
    public const RULES_VERSION = 1;

    public const MAX_COLORS = 8;

    public const MAX_DIMENSION = 30;

    public const MAX_CELLS = 900;

    /**
     * @param  list<array{name: string, hex: string}>  $palette
     */
    private function __construct(
        public int $width,
        public int $height,
        public string $cells,
        public array $palette,
    ) {}

    /**
     * @param  array{mode?: string, rules_version?: int, width: int, height: int, cells: string, palette: array<int, mixed>}  $document
     */
    public static function fromArray(array $document): self
    {
        if (($document['mode'] ?? 'color') !== 'color') {
            throw new InvalidArgumentException('Unsupported grid mode for the Color ruleset.');
        }
        if (($document['rules_version'] ?? self::RULES_VERSION) !== self::RULES_VERSION) {
            throw new InvalidArgumentException('Unsupported Color rules version.');
        }

        $width = $document['width'] ?? null;
        $height = $document['height'] ?? null;
        $cells = $document['cells'] ?? null;
        $palette = $document['palette'] ?? null;

        if (! is_int($width) || ! is_int($height) || $width < 1 || $height < 1
            || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
            || $width * $height > self::MAX_CELLS) {
            throw new InvalidArgumentException('Grid dimensions must be integers from 1 to 30.');
        }
        if (! is_array($palette) || ! array_is_list($palette) || $palette === [] || count($palette) > self::MAX_COLORS) {
            throw new InvalidArgumentException('A palette must contain between 1 and 8 entries.');
        }

        $normalizedPalette = [];
        $seenHex = [];
        foreach ($palette as $entry) {
            if (! is_array($entry) || ! isset($entry['name'], $entry['hex'])
                || ! is_string($entry['name']) || ! is_string($entry['hex'])) {
                throw new InvalidArgumentException('Each palette entry needs a name and a hex value.');
            }
            $name = trim($entry['name']);
            if ($name === '' || mb_strlen($name) > 40) {
                throw new InvalidArgumentException('Palette names must be 1 to 40 characters.');
            }
            if (preg_match('/^#[0-9A-F]{6}$/', $entry['hex']) !== 1) {
                throw new InvalidArgumentException('Palette hex values must use uppercase #RRGGBB.');
            }
            if (isset($seenHex[$entry['hex']])) {
                throw new InvalidArgumentException('Palette hex values must be unique.');
            }
            $seenHex[$entry['hex']] = true;
            $normalizedPalette[] = ['name' => $name, 'hex' => $entry['hex']];
        }

        $paletteSize = count($normalizedPalette);
        if (! is_string($cells) || strlen($cells) !== $width * $height) {
            throw new InvalidArgumentException('Grid cells must be a row-major string matching width × height.');
        }
        for ($index = 0; $index < strlen($cells); $index++) {
            $cell = $cells[$index];
            if ($cell < '0' || $cell > (string) $paletteSize) {
                throw new InvalidArgumentException('Every cell must be 0 or a palette colour ID.');
            }
        }

        return new self($width, $height, $cells, $normalizedPalette);
    }

    /** @return array{mode: string, rules_version: int, width: int, height: int, cells: string, palette: list<array{name: string, hex: string}>} */
    public function toArray(): array
    {
        return [
            'mode' => 'color',
            'rules_version' => self::RULES_VERSION,
            'width' => $this->width,
            'height' => $this->height,
            'cells' => $this->cells,
            'palette' => $this->palette,
        ];
    }

    /** @return array{rows: list<list<array{0: int, 1: int}>>, columns: list<list<array{0: int, 1: int}>>} */
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
        return hash('sha256', implode(':', [
            'color',
            (string) self::RULES_VERSION,
            (string) $this->width,
            (string) $this->height,
            json_encode($this->palette, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $this->cells,
        ]));
    }

    /** @return list<array{0: int, 1: int}> */
    private static function lineClues(string $line): array
    {
        $runs = [];
        $length = strlen($line);
        $index = 0;
        while ($index < $length) {
            $color = $line[$index];
            if ($color === '0') {
                $index++;

                continue;
            }

            $runLength = 1;
            while ($index + $runLength < $length && $line[$index + $runLength] === $color) {
                $runLength++;
            }
            $runs[] = [$runLength, (int) $color];
            $index += $runLength;
        }

        return $runs;
    }
}

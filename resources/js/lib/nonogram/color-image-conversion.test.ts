import { describe, expect, it } from 'vitest';
import { colorGridToStandard, rasterToColorGrid } from './color-image-conversion';
import { generateColorClues, validateColorGrid } from './color-grid';
import { generateClues } from './grid';
import { rasterToGrid, type RasterImage } from './image-conversion';

const settings = { threshold: 128, invert: false, resizeMethod: 'area-average' as const, maxColors: 4, whiteTolerance: 24 };
const source: RasterImage = { width: 4, height: 1, data: new Uint8ClampedArray([
    255, 0, 0, 255, 0, 0, 255, 255, 255, 255, 255, 255, 0, 0, 0, 0,
]) };

describe('color image conversion', () => {
    it('preserves distinct colors, removes white and transparent backgrounds, and generates color-aware clues', () => {
        const grid = rasterToColorGrid(source, 4, 1, settings);
        expect(grid).toMatchObject({ mode: 'color', rules_version: 1, width: 4, height: 1 });
        expect(grid.palette.map(entry => entry.hex).sort()).toEqual(['#0000FF', '#FF0000']);
        expect(grid.cells.slice(2)).toBe('00');
        expect(grid.cells[0]).not.toBe(grid.cells[1]);
        expect(grid.palette[Number(grid.cells[0]) - 1].hex).toBe('#FF0000');
        expect(generateColorClues(grid).rows[0]).toEqual([[1, Number(grid.cells[0])], [1, Number(grid.cells[1])]]);
    });

    it('offers a standard silhouette with regenerated clues without mutating the color version', () => {
        const grid = rasterToColorGrid(source, 4, 1, settings);
        const before = structuredClone(grid);
        const standard = colorGridToStandard(grid);
        expect(standard).toEqual({ version: 1, width: 4, height: 1, cells: '1100' });
        expect(generateClues(standard).rows).toEqual([[2]]);
        expect(grid).toEqual(before);
    });

    it('reduces colors deterministically and respects the requested palette size', () => {
        const grid = rasterToColorGrid(source, 4, 1, { ...settings, maxColors: 1 });
        expect(grid.palette).toEqual([{ name: 'Color 1', hex: '#800080' }]);
        expect(grid.cells).toBe('1100');
        expect(rasterToColorGrid(source, 4, 1, { ...settings, maxColors: 1 })).toEqual(grid);
    });

    it('keeps empty images valid and applies background tolerance and cleanup', () => {
        const nearWhite = { width: 1, height: 1, data: new Uint8ClampedArray([245, 245, 245, 255]) };
        const empty = rasterToColorGrid(nearWhite, 1, 1, settings);
        expect(empty.cells).toBe('0');
        expect(() => validateColorGrid(empty)).not.toThrow();
        expect(rasterToColorGrid(nearWhite, 1, 1, { ...settings, whiteTolerance: 0 }).cells).toBe('1');
        expect(rasterToColorGrid(nearWhite, 1, 1, { ...settings, whiteTolerance: 0, cleanupIsolatedCells: true }).cells).toBe('0');
    });

    it('averages colors, rotates and centers contain padding consistently with standard conversion', () => {
        const solid = { width: 2, height: 1, data: new Uint8ClampedArray([255, 0, 0, 255, 255, 0, 0, 255]) };
        const contained = rasterToColorGrid(solid, 2, 4, { ...settings, fitMode: 'contain' });
        expect(contained.cells).toBe('00111100');
        expect(rasterToGrid(solid, 2, 4, { ...settings, fitMode: 'contain', threshold: 200 }).cells).toBe('00111100');
        const rotated = rasterToColorGrid(source, 1, 4, { ...settings, rotation: 90 });
        expect(colorGridToStandard(rotated).cells).toBe('1100');
        const averaged = rasterToColorGrid(source, 1, 1, settings);
        expect(averaged.palette[0].hex).toBe('#BF80BF');
    });

    it('rejects invalid limits and malformed inputs', () => {
        expect(() => rasterToColorGrid(source, 4, 1, { ...settings, maxColors: 9 })).toThrow(/1 and 8/);
        expect(() => rasterToColorGrid(source, 4, 1, { ...settings, whiteTolerance: NaN })).toThrow(/tolerance/);
        expect(() => rasterToColorGrid(source, 31, 1, settings)).toThrow(/dimensions/);
        expect(() => rasterToColorGrid({ ...source, data: new Uint8ClampedArray(0) }, 4, 1, settings)).toThrow(/pixel data/);
    });
});

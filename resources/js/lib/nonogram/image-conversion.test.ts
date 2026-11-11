import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    DENSE_GRID_FILL_RATIO,
    MAX_IMAGE_BYTES,
    MAX_SOURCE_PIXELS,
    SPARSE_GRID_FILL_RATIO,
    decodeSupportedImage,
    gridDensityWarning,
    rasterToGrid,
    sniffImageMime,
    validateImageLimits,
    type RasterImage,
} from './image-conversion';

const settings = { threshold: 128, invert: false, resizeMethod: 'area-average' as const };

afterEach(() => vi.unstubAllGlobals());

function raster(width: number, height: number, pixels: number[][]): RasterImage {
    return { width, height, data: new Uint8ClampedArray(pixels.flat()) };
}

describe('image conversion core', () => {
    it('warns about sparse and dense grids without rejecting ordinary density', () => {
        const emptyTenByTen = { version: 1 as const, width: 10, height: 10, cells: '1'.repeat(10) + '0'.repeat(90) };
        const denseTenByTen = { version: 1 as const, width: 10, height: 10, cells: '1'.repeat(90) + '0'.repeat(10) };
        const balancedTenByTen = { version: 1 as const, width: 10, height: 10, cells: '1'.repeat(50) + '0'.repeat(50) };
        expect(SPARSE_GRID_FILL_RATIO).toBe(0.1);
        expect(DENSE_GRID_FILL_RATIO).toBe(0.9);
        expect(gridDensityWarning(emptyTenByTen)).toMatch(/very sparse/);
        expect(gridDensityWarning(denseTenByTen)).toMatch(/very dense/);
        expect(gridDensityWarning(balancedTenByTen)).toBeNull();
    });

    it('sniffs PNG, JPEG, and WebP from bytes rather than trusting a filename', () => {
        expect(sniffImageMime(new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10]))).toBe('image/png');
        expect(sniffImageMime(new Uint8Array([255, 216, 255, 0]))).toBe('image/jpeg');
        expect(sniffImageMime(new TextEncoder().encode('RIFF0000WEBP'))).toBe('image/webp');
        expect(sniffImageMime(new TextEncoder().encode('<svg/>'))).toBeNull();
    });

    it('decodes supported images locally with EXIF orientation and closes oversized bitmaps', async () => {
        const file = new File([new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10])], 'pixel.png', { type: 'image/png' });
        const bitmap = { width: 2, height: 3, close: vi.fn() } as unknown as ImageBitmap;
        const decode = vi.fn().mockResolvedValue(bitmap);
        vi.stubGlobal('createImageBitmap', decode);
        await expect(decodeSupportedImage(file)).resolves.toBe(bitmap);
        expect(decode).toHaveBeenCalledWith(file, { imageOrientation: 'from-image' });

        const oversized = { width: 5000, height: 4000, close: vi.fn() } as unknown as ImageBitmap;
        vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue(oversized));
        await expect(decodeSupportedImage(file)).rejects.toThrow(/4 megapixels/);
        expect(oversized.close).toHaveBeenCalledOnce();
    });

    it('rejects unsupported or mismatched image content before browser decoding', async () => {
        const file = new File([new TextEncoder().encode('<svg/>')], 'image.png', { type: 'image/png' });
        const decode = vi.fn();
        vi.stubGlobal('createImageBitmap', decode);
        await expect(decodeSupportedImage(file)).rejects.toThrow(/valid PNG, JPEG, or WebP/);
        expect(decode).not.toHaveBeenCalled();
    });

    it('enforces byte and decoded dimension limits', () => {
        expect(() => validateImageLimits(MAX_IMAGE_BYTES + 1, 1, 1)).toThrow(/no larger than/);
        expect(() => validateImageLimits(1, 2049, 2000)).toThrow(/4 megapixels/);
        expect(() => validateImageLimits(1, 0, 2)).toThrow(/invalid/);
        expect(() => validateImageLimits(1, 2000, 2000)).not.toThrow();
        expect(MAX_SOURCE_PIXELS).toBe(4_000_000);
    });

    it('uses weighted grayscale area averaging and deterministic nearest-neighbor sampling', () => {
        const source = raster(2, 2, [
            [0, 0, 0, 255], [255, 255, 255, 255],
            [255, 255, 255, 255], [255, 255, 255, 255],
        ]);
        expect(rasterToGrid(source, 1, 1, { ...settings, threshold: 200, resizeMethod: 'area-average' }))
            .toEqual({ version: 1, width: 1, height: 1, cells: '1' });
        expect(rasterToGrid(source, 1, 1, { ...settings, threshold: 200, resizeMethod: 'nearest' }))
            .toEqual({ version: 1, width: 1, height: 1, cells: '0' });
    });

    it('rotates in quarter-turns and supports contain/cover with adjustable crop alignment', () => {
        const blackWhite = raster(2, 1, [[0, 0, 0, 255], [255, 255, 255, 255]]);
        expect(rasterToGrid(blackWhite, 1, 2, { ...settings, rotation: 90 }).cells).toBe('10');
        expect(rasterToGrid(blackWhite, 1, 1, {
            ...settings, threshold: 128, fitMode: 'cover', cropX: 0, resizeMethod: 'nearest',
        }).cells).toBe('1');
        expect(rasterToGrid(blackWhite, 1, 1, {
            ...settings, threshold: 128, fitMode: 'cover', cropX: 1, resizeMethod: 'nearest',
        }).cells).toBe('0');

        const allBlack = raster(2, 1, [[0, 0, 0, 255], [0, 0, 0, 255]]);
        expect(rasterToGrid(allBlack, 1, 1, { ...settings, threshold: 100, fitMode: 'contain' }).cells).toBe('0');
        expect(rasterToGrid(allBlack, 1, 1, { ...settings, threshold: 100, fitMode: 'stretch' }).cells).toBe('1');
    });

    it('applies brightness and contrast adjustments before thresholding', () => {
        const gray = raster(1, 1, [[100, 100, 100, 255]]);
        expect(rasterToGrid(gray, 1, 1, { ...settings, threshold: 128 }).cells).toBe('1');
        expect(rasterToGrid(gray, 1, 1, { ...settings, threshold: 128, brightness: 20 }).cells).toBe('0');
        expect(rasterToGrid(gray, 1, 1, { ...settings, threshold: 128, contrast: 100 }).cells).toBe('1');
        expect(rasterToGrid(gray, 1, 1, { ...settings, threshold: 128, contrast: -100 }).cells).toBe('0');
    });

    it('removes isolated filled cells in one deterministic orthogonal-neighbor pass', () => {
        const source = raster(3, 3, [
            [255, 255, 255, 255], [255, 255, 255, 255], [255, 255, 255, 255],
            [0, 0, 0, 255], [0, 0, 0, 255], [255, 255, 255, 255],
            [255, 255, 255, 255], [255, 255, 255, 255], [0, 0, 0, 255],
        ]);
        expect(rasterToGrid(source, 3, 3, settings).cells).toBe('000110001');
        expect(rasterToGrid(source, 3, 3, { ...settings, cleanupIsolatedCells: true }).cells).toBe('000110000');
    });

    it('composites transparency on white and handles threshold equality and inversion', () => {
        const transparentBlack = raster(1, 1, [[0, 0, 0, 0]]);
        expect(rasterToGrid(transparentBlack, 1, 1, settings).cells).toBe('0');
        const gray = raster(1, 1, [[128, 128, 128, 255]]);
        expect(rasterToGrid(gray, 1, 1, { ...settings, threshold: 128 }).cells).toBe('0');
        expect(rasterToGrid(gray, 1, 1, { ...settings, threshold: 128, invert: true }).cells).toBe('1');
    });

    it('rejects invalid target grids, thresholds, and pixel buffers', () => {
        const source = raster(1, 1, [[0, 0, 0, 255]]);
        expect(() => rasterToGrid(source, 31, 1, settings)).toThrow(/dimensions/);
        expect(() => rasterToGrid(source, 1, 1, { ...settings, threshold: 256 })).toThrow(/Threshold/);
        expect(() => rasterToGrid(source, 1, 1, { ...settings, rotation: 45 as unknown as 0 })).toThrow(/multiple of 90/);
        expect(() => rasterToGrid(source, 1, 1, { ...settings, cropX: 2 })).toThrow(/between 0 and 1/);
        expect(() => rasterToGrid(source, 1, 1, { ...settings, brightness: 101 })).toThrow(/Brightness and contrast/);
        expect(() => rasterToGrid({ ...source, data: new Uint8ClampedArray(3) }, 1, 1, settings)).toThrow(/pixel data/);
    });

    it('produces canonical grids at maximum target dimensions', () => {
        const source = raster(1, 1, [[0, 0, 0, 255]]);
        const grid = rasterToGrid(source, 30, 30, settings);
        expect(grid).toMatchObject({ version: 1, width: 30, height: 30 });
        expect(grid.cells).toHaveLength(900);
        expect(grid.cells).toBe('1'.repeat(900));
    });
});

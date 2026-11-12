import { MAX_COLORS, validateColorGrid, type ColorGridDocument } from './color-grid';
import type { GridDocument } from './grid';
import { adjustChannel, sampleRaster, type ConversionSettings, type RasterImage } from './image-conversion';

export type ColorConversionSettings = ConversionSettings & { maxColors: number; whiteTolerance: number };
type RGB = [number, number, number];

/** Deterministic median-cut quantization over at most 900 sampled cells. White is the empty background. */
export function rasterToColorGrid(source: RasterImage, width: number, height: number, settings: ColorConversionSettings): ColorGridDocument {
    if (!Number.isInteger(settings.maxColors) || settings.maxColors < 1 || settings.maxColors > MAX_COLORS) {
        throw new Error('Choose between 1 and 8 colors.');
    }
    if (!Number.isInteger(settings.whiteTolerance) || settings.whiteTolerance < 0 || settings.whiteTolerance > 255) {
        throw new Error('White background tolerance must be an integer from 0 to 255.');
    }
    const colors = sampleRaster(source, width, height, settings).map(color => color.map(value => Math.round(adjustChannel(value, settings))) as RGB);
    const foreground = colors.map(color => Math.max(...color.map(value => 255 - value)) > settings.whiteTolerance);
    if (settings.cleanupIsolatedCells) {
        const original = [...foreground];
        for (let index = 0; index < original.length; index++) {
            const x = index % width;
            foreground[index] = original[index] && ((x > 0 && original[index - 1]) || (x < width - 1 && original[index + 1])
                || (index >= width && original[index - width]) || (index + width < original.length && original[index + width]));
        }
    }
    const pixels = colors.filter((_, index) => foreground[index]);
    const boxes: RGB[][] = pixels.length ? [pixels] : [];
    const ranges = (box: RGB[]) => [0, 1, 2].map(channel => Math.max(...box.map(color => color[channel])) - Math.min(...box.map(color => color[channel])));
    while (boxes.length < settings.maxColors) {
        let selected = -1;
        let widest = 0;
        boxes.forEach((box, index) => {
            const range = Math.max(...ranges(box));
            if (range > widest) { widest = range; selected = index; }
        });
        if (selected < 0) break;
        const box = boxes[selected];
        const spread = ranges(box);
        const channel = spread.indexOf(Math.max(...spread));
        box.sort((a, b) => a[channel] - b[channel]);
        const middle = Math.floor(box.length / 2);
        boxes.splice(selected, 1, box.slice(0, middle), box.slice(middle));
    }
    const centers = boxes.map(box => [0, 1, 2].map(channel => Math.round(box.reduce((sum, color) => sum + color[channel], 0) / box.length)) as RGB);
    const hex = (color: RGB) => `#${color.map(value => value.toString(16).padStart(2, '0')).join('').toUpperCase()}`;
    const uniqueCenters = centers.filter((color, index) => centers.findIndex(other => hex(color) === hex(other)) === index);
    if (!uniqueCenters.length) uniqueCenters.push([23, 35, 31]);
    const palette = uniqueCenters.map((color, index) => ({ name: `Color ${index + 1}`, hex: hex(color) }));
    const cells = colors.map((color, index) => {
        if (!foreground[index]) return '0';
        const distances = uniqueCenters.map(center => color.reduce((sum, value, channel) => sum + (value - center[channel]) ** 2, 0));
        return String(distances.indexOf(Math.min(...distances)) + 1);
    }).join('');
    const grid: ColorGridDocument = { mode: 'color', rules_version: 1, width, height, cells, palette };
    validateColorGrid(grid);
    return grid;
}

/** Preserve the silhouette when making a standard version of any color puzzle. */
export function colorGridToStandard(grid: ColorGridDocument): GridDocument {
    validateColorGrid(grid);
    return { version: 1, width: grid.width, height: grid.height, cells: [...grid.cells].map(cell => cell === '0' ? '0' : '1').join('') };
}

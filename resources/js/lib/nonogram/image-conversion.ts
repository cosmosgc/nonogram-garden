import type { GridDocument } from './grid';

export const SPARSE_GRID_FILL_RATIO = 0.1;
export const DENSE_GRID_FILL_RATIO = 0.9;

/** Advisory only; density never rejects a grid. */
export function gridDensityWarning(grid: GridDocument): string | null {
    const ratio = grid.cells.split('').filter(cell => cell === '1').length / grid.cells.length;
    if (ratio <= SPARSE_GRID_FILL_RATIO) return 'This grid is very sparse (10% filled or less). Check that important image details were not lost.';
    if (ratio >= DENSE_GRID_FILL_RATIO) return 'This grid is very dense (90% filled or more). Check that the threshold preserved useful contrast.';
    return null;
}

export const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
export const MAX_SOURCE_DIMENSION = 8192;
export const MAX_SOURCE_PIXELS = 4_000_000;

export type ResizeMethod = 'nearest' | 'area-average';
export type FitMode = 'stretch' | 'contain' | 'cover';
export type Rotation = 0 | 90 | 180 | 270;
export type RasterImage = { width: number; height: number; data: Uint8ClampedArray };
export type ConversionSettings = {
    threshold: number;
    invert: boolean;
    resizeMethod: ResizeMethod;
    fitMode?: FitMode;
    rotation?: Rotation;
    cropX?: number;
    cropY?: number;
    brightness?: number;
    contrast?: number;
    cleanupIsolatedCells?: boolean;
};

const MIME_SIGNATURES = [
    { mime: 'image/png', matches: (bytes: Uint8Array) => bytes.length >= 8 && [137, 80, 78, 71, 13, 10, 26, 10].every((byte, index) => bytes[index] === byte) },
    { mime: 'image/jpeg', matches: (bytes: Uint8Array) => bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff },
    { mime: 'image/webp', matches: (bytes: Uint8Array) => bytes.length >= 12 && ascii(bytes, 0, 4) === 'RIFF' && ascii(bytes, 8, 4) === 'WEBP' },
] as const;

function ascii(bytes: Uint8Array, start: number, length: number): string {
    return String.fromCharCode(...bytes.slice(start, start + length));
}

export function sniffImageMime(bytes: Uint8Array): string | null {
    return MIME_SIGNATURES.find(signature => signature.matches(bytes))?.mime ?? null;
}

export function validateImageLimits(size: number, width: number, height: number): void {
    if (!Number.isSafeInteger(size) || size <= 0 || size > MAX_IMAGE_BYTES) {
        throw new Error(`Choose an image no larger than ${Math.floor(MAX_IMAGE_BYTES / 1024 / 1024)} MB.`);
    }
    if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1
        || width > MAX_SOURCE_DIMENSION || height > MAX_SOURCE_DIMENSION || width * height > MAX_SOURCE_PIXELS) {
        throw new Error('Image dimensions are too large or invalid. Maximum source size is 4 megapixels.');
    }
}

/** Decode locally; uploading is a separate, explicit draft-creation step. */
export async function decodeSupportedImage(file: File): Promise<ImageBitmap> {
    validateImageLimits(file.size, 1, 1);
    const header = new Uint8Array(await file.slice(0, 12).arrayBuffer());
    const actualMime = sniffImageMime(header);
    if (!actualMime || (file.type && file.type !== actualMime)) {
        throw new Error('Use a valid PNG, JPEG, or WebP image.');
    }

    let bitmap: ImageBitmap;
    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        throw new Error('The image could not be decoded. Choose a valid PNG, JPEG, or WebP file.');
    }
    try {
        validateImageLimits(file.size, bitmap.width, bitmap.height);
        return bitmap;
    } catch (error) {
        bitmap.close();
        throw error;
    }
}

export function bitmapToRaster(bitmap: ImageBitmap): RasterImage {
    const canvas = document.createElement('canvas');
    canvas.width = bitmap.width;
    canvas.height = bitmap.height;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    if (!context) throw new Error('This browser could not prepare the image for conversion.');
    context.drawImage(bitmap, 0, 0);
    const pixels = context.getImageData(0, 0, bitmap.width, bitmap.height);
    canvas.width = 0;
    canvas.height = 0;

    return { width: bitmap.width, height: bitmap.height, data: pixels.data };
}

export async function imageFileToRaster(file: File): Promise<RasterImage> {
    const bitmap = await decodeSupportedImage(file);
    try {
        return bitmapToRaster(bitmap);
    } finally {
        bitmap.close();
    }
}

type RGB = [number, number, number];

function pixelColor(data: Uint8ClampedArray, offset: number): RGB {
    const alpha = data[offset + 3] / 255;
    const red = data[offset] * alpha + 255 * (1 - alpha);
    const green = data[offset + 1] * alpha + 255 * (1 - alpha);
    const blue = data[offset + 2] * alpha + 255 * (1 - alpha);

    return [red, green, blue];
}

/** Sample the image once per target cell, compositing transparency and padding on white. */
export function sampleRaster(
    source: RasterImage,
    targetWidth: number,
    targetHeight: number,
    settings: ConversionSettings,
): RGB[] {
    validateImageLimits(1, source.width, source.height);
    if (source.data.length !== source.width * source.height * 4) throw new Error('Image pixel data has an invalid size.');
    if (!Number.isInteger(targetWidth) || !Number.isInteger(targetHeight)
        || targetWidth < 1 || targetHeight < 1 || targetWidth > 30 || targetHeight > 30) {
        throw new Error('Target grid dimensions must be integers from 1 to 30.');
    }
    if (!Number.isInteger(settings.threshold) || settings.threshold < 0 || settings.threshold > 255) {
        throw new Error('Threshold must be an integer from 0 to 255.');
    }
    if (settings.resizeMethod !== 'nearest' && settings.resizeMethod !== 'area-average') {
        throw new Error('Unsupported image resize method.');
    }
    const fitMode = settings.fitMode ?? 'stretch';
    const rotation = settings.rotation ?? 0;
    const cropX = settings.cropX ?? 0.5;
    const cropY = settings.cropY ?? 0.5;
    if (!['stretch', 'contain', 'cover'].includes(fitMode)) throw new Error('Unsupported image fit mode.');
    if (![0, 90, 180, 270].includes(rotation)) throw new Error('Rotation must be a multiple of 90 degrees.');
    if (!Number.isFinite(cropX) || cropX < 0 || cropX > 1 || !Number.isFinite(cropY) || cropY < 0 || cropY > 1) {
        throw new Error('Crop alignment must be between 0 and 1.');
    }
    const brightness = settings.brightness ?? 0;
    const contrast = settings.contrast ?? 0;
    if (!Number.isInteger(brightness) || brightness < -100 || brightness > 100
        || !Number.isInteger(contrast) || contrast < -100 || contrast > 100) {
        throw new Error('Brightness and contrast must be integers from -100 to 100.');
    }

    const rotatedWidth = rotation === 90 || rotation === 270 ? source.height : source.width;
    const rotatedHeight = rotation === 90 || rotation === 270 ? source.width : source.height;
    let scaleX = targetWidth / rotatedWidth;
    let scaleY = targetHeight / rotatedHeight;
    let offsetX = 0;
    let offsetY = 0;
    if (fitMode !== 'stretch') {
        const scale = fitMode === 'contain'
            ? Math.min(targetWidth / rotatedWidth, targetHeight / rotatedHeight)
            : Math.max(targetWidth / rotatedWidth, targetHeight / rotatedHeight);
        scaleX = scaleY = scale;
        const extraWidth = rotatedWidth * scale - targetWidth;
        const extraHeight = rotatedHeight * scale - targetHeight;
        offsetX = fitMode === 'contain' ? -extraWidth / 2 : -extraWidth * cropX;
        offsetY = fitMode === 'contain' ? -extraHeight / 2 : -extraHeight * cropY;
    }

    const rotatedColor = (rotatedX: number, rotatedY: number): RGB => {
        rotatedX = Math.max(0, Math.min(rotatedWidth - 1, rotatedX));
        rotatedY = Math.max(0, Math.min(rotatedHeight - 1, rotatedY));
        let sourceX: number;
        let sourceY: number;
        if (rotation === 90) {
            sourceX = rotatedY;
            sourceY = source.height - 1 - rotatedX;
        } else if (rotation === 180) {
            sourceX = source.width - 1 - rotatedX;
            sourceY = source.height - 1 - rotatedY;
        } else if (rotation === 270) {
            sourceX = source.width - 1 - rotatedY;
            sourceY = rotatedX;
        } else {
            sourceX = rotatedX;
            sourceY = rotatedY;
        }
        return pixelColor(source.data, (sourceY * source.width + sourceX) * 4);
    };
    const colors: RGB[] = [];
    for (let targetY = 0; targetY < targetHeight; targetY++) {
        for (let targetX = 0; targetX < targetWidth; targetX++) {
            let average: RGB;
            const targetLeft = targetX;
            const targetTop = targetY;
            const targetRight = targetX + 1;
            const targetBottom = targetY + 1;
            if (settings.resizeMethod === 'nearest') {
                const centerX = targetX + 0.5;
                const centerY = targetY + 0.5;
                const sourceX = Math.floor((centerX - offsetX) / scaleX);
                const sourceY = Math.floor((centerY - offsetY) / scaleY);
                average = centerX < offsetX || centerX >= offsetX + rotatedWidth * scaleX
                    || centerY < offsetY || centerY >= offsetY + rotatedHeight * scaleY
                    ? [255, 255, 255]
                    : rotatedColor(sourceX, sourceY);
            } else {
                const imageLeft = Math.max(targetLeft, offsetX);
                const imageTop = Math.max(targetTop, offsetY);
                const imageRight = Math.min(targetRight, offsetX + rotatedWidth * scaleX);
                const imageBottom = Math.min(targetBottom, offsetY + rotatedHeight * scaleY);
                let totalWeight = 1 - Math.max(0, imageRight - imageLeft) * Math.max(0, imageBottom - imageTop);
                const weightedColor: RGB = [255 * totalWeight, 255 * totalWeight, 255 * totalWeight];
                if (imageRight > imageLeft && imageBottom > imageTop) {
                    const sourceLeft = (imageLeft - offsetX) / scaleX;
                    const sourceTop = (imageTop - offsetY) / scaleY;
                    const sourceRight = (imageRight - offsetX) / scaleX;
                    const sourceBottom = (imageBottom - offsetY) / scaleY;
                    for (let sourceY = Math.floor(sourceTop); sourceY < Math.ceil(sourceBottom); sourceY++) {
                        const overlapY = Math.min(sourceBottom, sourceY + 1) - Math.max(sourceTop, sourceY);
                        for (let sourceX = Math.floor(sourceLeft); sourceX < Math.ceil(sourceRight); sourceX++) {
                            const overlapX = Math.min(sourceRight, sourceX + 1) - Math.max(sourceLeft, sourceX);
                            const weight = overlapX * overlapY * scaleX * scaleY;
                            const color = rotatedColor(sourceX, sourceY);
                            for (let channel = 0; channel < 3; channel++) weightedColor[channel] += color[channel] * weight;
                            totalWeight += weight;
                        }
                    }
                }
                average = weightedColor.map(value => value / totalWeight) as RGB;
            }
            colors.push(average);
        }
    }

    return colors;
}

export function adjustChannel(value: number, settings: ConversionSettings): number {
    return Math.max(0, Math.min(255, (value - 128) * (1 + (settings.contrast ?? 0) / 100) + 128 + (settings.brightness ?? 0) * 2.55));
}

/** Convert RGBA pixels into the canonical row-major monochrome grid. */
export function rasterToGrid(source: RasterImage, targetWidth: number, targetHeight: number, settings: ConversionSettings): GridDocument {
    const cells = sampleRaster(source, targetWidth, targetHeight, settings).map(([red, green, blue]) => {
        const average = adjustChannel(red * 0.2126 + green * 0.7152 + blue * 0.0722, settings);
        return (settings.invert ? average >= settings.threshold : average < settings.threshold) ? '1' : '0';
    });

    if (settings.cleanupIsolatedCells) {
        const original = cells.join('');
        for (let y = 0; y < targetHeight; y++) {
            for (let x = 0; x < targetWidth; x++) {
                const index = y * targetWidth + x;
                if (original[index] !== '1') continue;
                const hasFilledNeighbor = (x > 0 && original[index - 1] === '1')
                    || (x + 1 < targetWidth && original[index + 1] === '1')
                    || (y > 0 && original[index - targetWidth] === '1')
                    || (y + 1 < targetHeight && original[index + targetWidth] === '1');
                if (!hasFilledNeighbor) cells[index] = '0';
            }
        }
    }

    return { version: 1, width: targetWidth, height: targetHeight, cells: cells.join('') };
}

import { validateImageLimits, type RasterImage } from '../lib/nonogram/image-conversion';

self.onmessage = async (event: MessageEvent<{ id: number; file: File }>) => {
    const { id, file } = event.data;
    let bitmap: ImageBitmap | null = null;
    let canvas: OffscreenCanvas | null = null;

    try {
        if (typeof createImageBitmap !== 'function' || typeof OffscreenCanvas === 'undefined') {
            throw new Error('This browser does not support off-thread image decoding.');
        }
        validateImageLimits(file.size, 1, 1);
        const header = new Uint8Array(await file.slice(0, 12).arrayBuffer());
        const mime = header.length >= 8 && [137, 80, 78, 71, 13, 10, 26, 10].every((byte, index) => header[index] === byte)
            ? 'image/png'
            : header[0] === 0xff && header[1] === 0xd8 && header[2] === 0xff
                ? 'image/jpeg'
                : header.length >= 12 && String.fromCharCode(...header.slice(0, 4)) === 'RIFF'
                    && String.fromCharCode(...header.slice(8, 12)) === 'WEBP'
                    ? 'image/webp'
                    : null;
        if (!mime || (file.type && file.type !== mime)) {
            throw new Error('Use a valid PNG, JPEG, or WebP image.');
        }

        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        validateImageLimits(file.size, bitmap.width, bitmap.height);
        canvas = new OffscreenCanvas(bitmap.width, bitmap.height);
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context) {
            throw new Error('This browser could not prepare the image for conversion.');
        }

        context.drawImage(bitmap, 0, 0);
        const pixels = context.getImageData(0, 0, bitmap.width, bitmap.height).data;
        const raster: RasterImage = { width: bitmap.width, height: bitmap.height, data: pixels };
        (self.postMessage as (message: unknown, transfer: Transferable[]) => void)({ id, raster }, [pixels.buffer]);
    } catch (error) {
        self.postMessage({ id, error: error instanceof Error ? error.message : 'The image could not be decoded.' });
    } finally {
        bitmap?.close();
        if (canvas) {
            canvas.width = 0;
            canvas.height = 0;
        }
    }
};

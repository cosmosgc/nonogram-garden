import { rasterToGrid, type ConversionSettings, type RasterImage } from '../lib/nonogram/image-conversion';

type ConversionMessage = {
    id: number;
    source: RasterImage;
    width: number;
    height: number;
    settings: ConversionSettings;
};
type WorkerScope = {
    onmessage: ((event: MessageEvent<ConversionMessage>) => void) | null;
    postMessage: (message: unknown) => void;
};

const workerScope = self as unknown as WorkerScope;
workerScope.onmessage = event => {
    const { id, source, width, height, settings } = event.data;
    try {
        workerScope.postMessage({ id, grid: rasterToGrid(source, width, height, settings) });
    } catch (error) {
        workerScope.postMessage({ id, error: error instanceof Error ? error.message : 'The image could not be converted.' });
    }
};

<script setup lang="ts">
import { computed, onUnmounted, ref, shallowRef, watch } from 'vue';
import { generateClues, validateGrid, type GridDocument } from '../lib/nonogram/grid';
import { analyzeUniqueness, type UniquenessStatus } from '../lib/nonogram/uniqueness-solver';
import { gridDensityWarning, imageFileToRaster, rasterToGrid, validateImageLimits, type FitMode, type RasterImage, type ResizeMethod, type Rotation } from '../lib/nonogram/image-conversion';

const root = document.getElementById('nonogram-image-importer');
const userId = Number(root?.dataset.userId);
const editorNewUrl = root?.dataset.editorNewUrl ?? '';
const source = shallowRef<RasterImage | null>(null);
const fileName = ref('');
const sourceUrl = ref<string | null>(null);
const decoding = ref(false);
const width = ref(10);
const height = ref(10);
const threshold = ref(128);
const brightness = ref(0);
const contrast = ref(0);
const cleanupIsolatedCells = ref(false);
const invert = ref(false);
const resizeMethod = ref<ResizeMethod>('area-average');
const fitMode = ref<FitMode>('contain');
const rotation = ref<Rotation>(0);
const cropX = ref(0.5);
const cropY = ref(0.5);
const grid = ref<GridDocument | null>(null);
const error = ref('');
const analysisStatus = ref<UniquenessStatus | null>(null);
const analysisMessage = ref('');
const analyzing = ref(false);
let analysisWorker: Worker | null = null;
let analysisId = 0;
const converting = ref(false);
let activeWorker: Worker | null = null;
let conversionId = 0;
let conversionTimer: ReturnType<typeof setTimeout> | undefined;
let decodeWorker: Worker | null = null;
let decodeGeneration = 0;
let rejectActiveDecode: ((reason: Error) => void) | null = null;

const clues = computed(() => grid.value ? generateClues(grid.value) : null);
const filledCells = computed(() => grid.value?.cells.split('').filter(cell => cell === '1').length ?? 0);
const densityWarning = computed(() => grid.value ? gridDensityWarning(grid.value) : null);
const handoffKey = `nonogram:image-import:${userId}`;

function revokeSourceUrl() {
    if (sourceUrl.value) URL.revokeObjectURL(sourceUrl.value);
    sourceUrl.value = null;
}

async function chooseImage(event: Event) {
    cancelConversion();
    cancelImageDecode();
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    input.value = '';
    revokeSourceUrl();
    source.value = null;
    grid.value = null;
    cancelAnalysis();
    analysisStatus.value = null;
    analysisMessage.value = '';
    error.value = '';
    fileName.value = file.name;
    sourceUrl.value = URL.createObjectURL(file);
    const generation = ++decodeGeneration;

    try {
        decoding.value = true;
        let raster: RasterImage;
        try {
            raster = await decodeImageInWorker(file, generation);
        } catch {
            if (generation !== decodeGeneration) return;
            raster = await imageFileToRaster(file);
        }
        if (generation !== decodeGeneration) return;
        source.value = raster;
    } catch (cause) {
        if (generation !== decodeGeneration) return;
        fileName.value = '';
        error.value = cause instanceof Error ? cause.message : 'The image could not be loaded.';
    } finally {
        if (generation === decodeGeneration) decoding.value = false;
    }
}

function decodeImageInWorker(file: File, generation: number): Promise<RasterImage> {
    if (typeof Worker === 'undefined') {
        return imageFileToRaster(file);
    }

    return new Promise((resolve, reject) => {
        let worker: Worker;
        try {
            worker = new Worker(new URL('../workers/image-decode.worker.ts', import.meta.url), { type: 'module' });
        } catch (cause) {
            reject(cause);
            return;
        }

        decodeWorker = worker;
        rejectActiveDecode = reject;
        const stopWorker = () => {
            worker.terminate();
            if (decodeWorker === worker) decodeWorker = null;
            if (rejectActiveDecode === reject) rejectActiveDecode = null;
        };
        worker.onmessage = event => {
            if (generation !== decodeGeneration) return;
            stopWorker();
            if (event.data.error) {
                reject(new Error(String(event.data.error)));
                return;
            }
            const raster = event.data.raster as RasterImage | undefined;
            try {
                if (!raster || !(raster.data instanceof Uint8ClampedArray)) {
                    throw new Error('The image decoder returned invalid pixel data.');
                }
                validateImageLimits(file.size, raster.width, raster.height);
                if (raster.data.length !== raster.width * raster.height * 4) {
                    throw new Error('The image decoder returned invalid pixel data.');
                }
                resolve(raster);
            } catch (cause) {
                reject(cause);
            }
        };
        worker.onerror = event => {
            if (generation !== decodeGeneration) return;
            stopWorker();
            reject(new Error(event.message || 'Image decoding failed in the worker.'));
        };
        try {
            worker.postMessage({ id: generation, file });
        } catch (cause) {
            stopWorker();
            reject(cause);
        }
    });
}

function cancelImageDecode() {
    decodeGeneration++;
    decodeWorker?.terminate();
    decodeWorker = null;
    rejectActiveDecode?.(new Error('Image decoding cancelled.'));
    rejectActiveDecode = null;
    decoding.value = false;
}

function cancelAnalysis() {
    analysisWorker?.terminate();
    analysisWorker = null;
    analysisId++;
    if (analyzing.value) {
        analysisStatus.value = 'unknown';
        analysisMessage.value = 'Analysis cancelled; uniqueness remains unknown.';
    }
    analyzing.value = false;
}

function runAnalysisInMainThread(currentGrid: GridDocument) {
    try {
        const currentClues = generateClues(currentGrid);
        analysisStatus.value = analyzeUniqueness(currentGrid.width, currentGrid.height, currentClues.rows, currentClues.columns);
        analysisMessage.value = 'This client-side result is advisory. The server performs the authoritative publication check.';
    } catch (cause) {
        analysisStatus.value = 'unknown';
        analysisMessage.value = cause instanceof Error ? cause.message : 'Analysis failed; uniqueness remains unknown.';
    } finally {
        analyzing.value = false;
    }
}

function analyzeGrid() {
    const currentGrid = grid.value;
    if (!currentGrid || analyzing.value) return;
    cancelAnalysis();
    const id = ++analysisId;
    analyzing.value = true;
    analysisStatus.value = null;
    analysisMessage.value = '';

    try {
        const worker = new Worker(new URL('../workers/uniqueness.worker.ts', import.meta.url), { type: 'module' });
        analysisWorker = worker;
        worker.onmessage = event => {
            if (event.data.id !== id) return;
            worker.terminate();
            analysisWorker = null;
            analyzing.value = false;
            if (event.data.error) {
                analysisStatus.value = 'unknown';
                analysisMessage.value = `${event.data.error} The server will still verify publication.`;
                return;
            }
            analysisStatus.value = event.data.status as UniquenessStatus;
            analysisMessage.value = 'This client-side result is advisory. The server performs the authoritative publication check.';
        };
        worker.onerror = () => {
            if (id !== analysisId) return;
            worker.terminate();
            analysisWorker = null;
            runAnalysisInMainThread(currentGrid);
        };
        worker.postMessage({ id, grid: currentGrid });
    } catch {
        analysisWorker?.terminate();
        analysisWorker = null;
        runAnalysisInMainThread(currentGrid);
    }
}

watch([source, width, height, threshold, brightness, contrast, invert, cleanupIsolatedCells, fitMode, rotation, cropX, cropY, resizeMethod], () => {
    if (conversionTimer) clearTimeout(conversionTimer);
    cancelActiveConversion();
    cancelAnalysis();
    analysisStatus.value = null;
    analysisMessage.value = '';
    if (!source.value) return;
    conversionTimer = setTimeout(() => {
        conversionTimer = undefined;
        convert();
    }, 180);
});

function acceptConversion(converted: GridDocument) {
    validateGrid(converted);
    grid.value = converted;
    analysisStatus.value = null;
    analysisMessage.value = '';
    converting.value = false;
}

function failConversion(message: string) {
    error.value = message;
    converting.value = false;
}

function fallbackConversion(
    currentSource: RasterImage,
    targetWidth: number,
    targetHeight: number,
    settings: Parameters<typeof rasterToGrid>[3],
) {
    try {
        acceptConversion(rasterToGrid(currentSource, targetWidth, targetHeight, settings));
    } catch (cause) {
        failConversion(cause instanceof Error ? cause.message : 'The image could not be converted.');
    }
}

function convert() {
    const currentSource = source.value;
    if (!currentSource || converting.value) return;
    if (conversionTimer) {
        clearTimeout(conversionTimer);
        conversionTimer = undefined;
    }
    error.value = '';
    cancelAnalysis();
    analysisStatus.value = null;
    analysisMessage.value = '';
    converting.value = true;
    const id = ++conversionId;
    const settings = {
        threshold: threshold.value,
        invert: invert.value,
        resizeMethod: resizeMethod.value,
        fitMode: fitMode.value,
        rotation: rotation.value,
        cropX: cropX.value,
        cropY: cropY.value,
        brightness: brightness.value,
        contrast: contrast.value,
        cleanupIsolatedCells: cleanupIsolatedCells.value,
    };

    if (typeof Worker !== 'undefined') {
        try {
            const worker = new Worker(new URL('../workers/image-conversion.worker.ts', import.meta.url), { type: 'module' });
            activeWorker = worker;
            worker.onmessage = event => {
                if (event.data.id !== id) return;
                worker.terminate();
                activeWorker = null;
                if (event.data.error) fallbackConversion(currentSource, width.value, height.value, settings);
                else {
                    try { acceptConversion(event.data.grid as GridDocument); }
                    catch (cause) { failConversion(cause instanceof Error ? cause.message : 'The image could not be converted.'); }
                }
            };
            worker.onerror = () => {
                if (id !== conversionId) return;
                worker.terminate();
                activeWorker = null;
                fallbackConversion(currentSource, width.value, height.value, settings);
            };
            const copy = { ...currentSource, data: new Uint8ClampedArray(currentSource.data) };
            worker.postMessage({ id, source: copy, width: width.value, height: height.value, settings }, [copy.data.buffer]);
            return;
        } catch {
            activeWorker?.terminate();
            activeWorker = null;
        }
    }

    try {
        fallbackConversion(currentSource, width.value, height.value, settings);
    } catch (cause) {
        failConversion(cause instanceof Error ? cause.message : 'The image could not be converted.');
    }
}

function cancelActiveConversion() {
    activeWorker?.terminate();
    activeWorker = null;
    conversionId++;
    converting.value = false;
}

function cancelConversion() {
    if (conversionTimer) {
        clearTimeout(conversionTimer);
        conversionTimer = undefined;
    }
    cancelActiveConversion();
    error.value = 'Image conversion cancelled.';
}

function editPuzzle() {
    if (!grid.value) return;
    if (!editorNewUrl) {
        error.value = 'The editor route is unavailable. Reload this page and try again.';
        return;
    }
    try {
        sessionStorage.setItem(handoffKey, JSON.stringify(grid.value));
        window.location.assign(`${editorNewUrl}?image_import=1`);
    } catch {
        error.value = 'This browser could not pass the generated grid to the editor. Check session storage settings and try again.';
    }
}

onUnmounted(() => {
    if (conversionTimer) clearTimeout(conversionTimer);
    cancelConversion();
    cancelImageDecode();
    cancelAnalysis();
    revokeSourceUrl();
});
</script>

<template>
  <main class="mx-auto max-w-5xl space-y-6 p-6 text-slate-900">
    <header>
      <a :href="editorNewUrl" class="underline">Back to editor</a>
      <h1 class="mt-3 text-3xl font-bold">Import an image</h1>
      <p class="mt-2 max-w-3xl text-slate-700">Choose a PNG, JPEG, or WebP image. The grid preview updates automatically as you adjust settings. Images are decoded in this browser; the source file is not uploaded or saved.</p>
    </header>

    <section class="grid gap-6 rounded-lg border bg-white p-6 lg:grid-cols-2" aria-label="Image conversion controls">
      <div class="space-y-4">
        <label class="block font-medium">Source image
          <input class="mt-2 block w-full" type="file" accept="image/png,image/jpeg,image/webp" :disabled="converting" @change="chooseImage">
        </label>
        <div v-if="decoding" class="flex items-center gap-3" role="status" aria-live="polite" aria-busy="true">
          <span class="size-4 animate-spin rounded-full border-2 border-emerald-900 border-r-transparent" aria-hidden="true"></span>
          <span>Decoding image locally…</span>
          <button type="button" class="rounded border px-3 py-1" @click="cancelImageDecode">Cancel decode</button>
        </div>
        <p v-if="fileName" class="text-sm text-slate-600">{{ fileName }}<template v-if="source"> · {{ source.width }}×{{ source.height }}</template></p>
        <div v-if="sourceUrl" class="rounded border bg-slate-50 p-3">
          <img :src="sourceUrl" alt="Selected image preview, stored only in this browser session" class="mx-auto max-h-72 max-w-full object-contain">
        </div>
        <div class="grid grid-cols-2 gap-4">
          <label class="grid gap-1">Grid width
            <input v-model.number="width" type="number" min="1" max="30" class="rounded border-slate-300" :disabled="converting">
          </label>
          <label class="grid gap-1">Grid height
            <input v-model.number="height" type="number" min="1" max="30" class="rounded border-slate-300" :disabled="converting">
          </label>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <label class="grid gap-1">Fit and crop
            <select v-model="fitMode" class="rounded border-slate-300" :disabled="converting">
              <option value="contain">Contain (show all)</option>
              <option value="cover">Cover (crop to grid)</option>
              <option value="stretch">Stretch</option>
            </select>
          </label>
          <label class="grid gap-1">Rotate
            <select v-model.number="rotation" class="rounded border-slate-300" :disabled="converting">
              <option :value="0">0°</option><option :value="90">90° clockwise</option>
              <option :value="180">180°</option><option :value="270">270° clockwise</option>
            </select>
          </label>
        </div>
        <div v-if="fitMode === 'cover'" class="grid gap-3">
          <label class="grid gap-1">Crop horizontal position: {{ Math.round(cropX * 100) }}%
            <input v-model.number="cropX" type="range" min="0" max="1" step="0.01" :disabled="converting">
          </label>
          <label class="grid gap-1">Crop vertical position: {{ Math.round(cropY * 100) }}%
            <input v-model.number="cropY" type="range" min="0" max="1" step="0.01" :disabled="converting">
          </label>
        </div>
        <label class="block">Darkness threshold: <output>{{ threshold }}</output>
          <input v-model.number="threshold" class="mt-2 block w-full" type="range" min="0" max="255" step="1" :disabled="converting">
        </label>
        <label class="grid gap-1">Brightness: {{ brightness }}
          <input v-model.number="brightness" type="range" min="-100" max="100" step="1" :disabled="converting">
        </label>
        <label class="grid gap-1">Contrast: {{ contrast }}
          <input v-model.number="contrast" type="range" min="-100" max="100" step="1" :disabled="converting">
        </label>
        <label class="flex items-center gap-2"><input v-model="cleanupIsolatedCells" type="checkbox" :disabled="converting"> Remove isolated filled cells with no orthogonal filled neighbors</label>
        <label class="flex items-center gap-2"><input v-model="invert" type="checkbox" :disabled="converting"> Invert light and dark cells</label>
        <label class="grid gap-1">Resize method
          <select v-model="resizeMethod" class="rounded border-slate-300" :disabled="converting">
            <option value="area-average">Area average</option>
            <option value="nearest">Nearest neighbor</option>
          </select>
        </label>
        <div class="flex flex-wrap gap-2">
          <button type="button" :disabled="!source || converting || analyzing" class="rounded bg-emerald-900 px-4 py-2 font-semibold text-white disabled:opacity-50" @click="convert">{{ converting ? 'Converting…' : 'Update preview now' }}</button>
          <button v-if="converting" type="button" class="rounded border px-4 py-2" @click="cancelConversion">Cancel conversion</button>
        </div>
      </div>

      <div class="space-y-4">
        <h2 class="text-xl font-semibold">Pixelated result</h2>
        <p v-if="!grid" class="rounded border border-dashed p-6 text-slate-600">Select an image and update the preview to see the generated grid.</p>
        <template v-else>
          <p role="status" aria-live="polite">{{ converting ? 'Updating preview… ' : '' }}{{ grid.width }}×{{ grid.height }} · {{ filledCells }} filled {{ filledCells === 1 ? 'cell' : 'cells' }}</p>
          <p v-if="densityWarning" role="status" class="rounded bg-amber-50 p-3 text-amber-900">{{ densityWarning }} This is guidance only; it does not block saving or publishing.</p>
          <div class="max-h-80 overflow-auto rounded border bg-slate-50 p-3">
            <div class="mx-auto grid w-fit" role="img" :aria-label="`Generated ${grid.width} by ${grid.height} monochrome grid`" :style="{ gridTemplateColumns: `repeat(${grid.width}, minmax(12px, 20px))` }">
              <span v-for="(cell, index) in grid.cells" :key="index" class="aspect-square border border-slate-300" :class="cell === '1' ? 'bg-slate-900' : 'bg-white'" aria-hidden="true"></span>
            </div>
          </div>
          <div v-if="clues" class="grid gap-4 text-sm sm:grid-cols-2">
            <div><h3 class="font-semibold">Row clues</h3><ol class="mt-1 list-inside list-decimal"> <li v-for="(line, index) in clues.rows" :key="index">{{ line.join(' ') }}</li></ol></div>
            <div><h3 class="font-semibold">Column clues</h3><ol class="mt-1 list-inside list-decimal"><li v-for="(line, index) in clues.columns" :key="index">{{ line.join(' ') }}</li></ol></div>
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button" :disabled="analyzing" class="rounded border px-4 py-2 font-semibold disabled:opacity-50" @click="analyzeGrid">{{ analyzing ? 'Analyzing uniqueness…' : 'Analyze uniqueness' }}</button>
            <button v-if="analyzing" type="button" class="rounded border px-4 py-2" @click="cancelAnalysis">Cancel analysis</button>
            <button type="button" class="rounded border px-4 py-2 font-semibold" @click="editPuzzle">Edit puzzle</button>
          </div>
          <p v-if="analysisStatus" role="status" aria-live="polite" class="rounded border bg-slate-50 p-3">
            <strong>Client uniqueness result: {{ analysisStatus }}.</strong>
            {{ analysisStatus === 'unique' ? 'One solution was found within the client analysis budget.' : analysisStatus === 'ambiguous' ? 'Multiple solutions were found; edit the puzzle to make it logically unique.' : analysisStatus === 'inconsistent' ? 'The clues have no matching solution; review the grid or conversion settings.' : 'The analysis could not determine uniqueness within its resource budget.' }}
            {{ analysisMessage }}
          </p>
        </template>
      </div>
    </section>
    <p v-if="error" role="alert" class="rounded border border-red-300 bg-red-50 p-3 text-red-800">{{ error }}</p>
    <p class="text-sm text-slate-600">This creates a draft grid only. Review it in the editor; server-side uniqueness validation is still required before publication.</p>
  </main>
</template>

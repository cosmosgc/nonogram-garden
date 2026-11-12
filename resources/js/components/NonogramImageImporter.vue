<script setup lang="ts">
import { computed, onUnmounted, ref, shallowRef, watch } from 'vue';
import { generateClues } from '../lib/nonogram/grid';
import { analyzeUniqueness, type UniquenessStatus } from '../lib/nonogram/uniqueness-solver';
import { gridDensityWarning, imageFileToRaster, rasterToGrid, validateImageLimits, type FitMode, type RasterImage, type ResizeMethod, type Rotation } from '../lib/nonogram/image-conversion';
import { generateColorClues, type ColorClue } from '../lib/nonogram/color-grid';
import { colorGridToStandard, rasterToColorGrid, type ColorConversionSettings } from '../lib/nonogram/color-image-conversion';
import { validateDraftGrid, type DraftGridDocument } from '../lib/nonogram/draft-api';
import { analyzeColorUniqueness } from '../lib/nonogram/color-uniqueness-solver';
import { readJsonResponse } from '../lib/nonogram/http';

const root = document.getElementById('nonogram-image-importer');
const editorNewUrl = root?.dataset.editorNewUrl ?? '';
const editorEditUrlTemplate = root?.dataset.editorEditUrlTemplate ?? '';
const draftStoreUrl = root?.dataset.draftStoreUrl ?? '';
const source = shallowRef<RasterImage | null>(null);
const sourceFile = shallowRef<File | null>(null);
const fileName = ref('');
const title = ref('Imported image puzzle');
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
const importMode = ref<'classic' | 'color'>('classic');
const standardVersion = ref(false);
const maxColors = ref(4);
const whiteTolerance = ref(24);
const convertedGrid = ref<DraftGridDocument | null>(null);
const grid = computed(() => convertedGrid.value && 'mode' in convertedGrid.value && standardVersion.value
    ? colorGridToStandard(convertedGrid.value) : convertedGrid.value);
const previewPending = ref(false);
const error = ref('');
const analysisStatus = ref<UniquenessStatus | null>(null);
const analysisMessage = ref('');
const analyzing = ref(false);
let analysisWorker: Worker | null = null;
let analysisId = 0;
const converting = ref(false);
const creatingDraft = ref(false);
let activeWorker: Worker | null = null;
let conversionId = 0;
let conversionTimer: ReturnType<typeof setTimeout> | undefined;
let decodeWorker: Worker | null = null;
let decodeGeneration = 0;
let rejectActiveDecode: ((reason: Error) => void) | null = null;
let decodeTimeout: ReturnType<typeof setTimeout> | undefined;
let conversionTimeout: ReturnType<typeof setTimeout> | undefined;

const clues = computed(() => grid.value ? ('mode' in grid.value ? generateColorClues(grid.value) : generateClues(grid.value)) : null);
const filledCells = computed(() => grid.value?.cells.split('').filter(cell => cell !== '0').length ?? 0);
const densityWarning = computed(() => grid.value ? gridDensityWarning('mode' in grid.value ? colorGridToStandard(grid.value) : grid.value) : null);
const canCreate = computed(() => Boolean(grid.value && sourceFile.value && !previewPending.value && !converting.value && !decoding.value && !creatingDraft.value));
const colorPalette = computed(() => convertedGrid.value && 'mode' in convertedGrid.value ? convertedGrid.value.palette : []);

function clueLabel(line: number[] | ColorClue[]): string {
    return line.map(item => Array.isArray(item) ? `${item[0]} (${colorPalette.value[item[1] - 1]?.name})` : String(item)).join(' ') || '0';
}

function previewColor(cell: string): string | undefined {
    return grid.value && 'mode' in grid.value && cell !== '0' ? grid.value.palette[Number(cell) - 1]?.hex : undefined;
}

function revokeSourceUrl() {
    if (sourceUrl.value) URL.revokeObjectURL(sourceUrl.value);
    sourceUrl.value = null;
}

async function chooseImage(event: Event) {
    if (creatingDraft.value) return;
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    cancelConversion();
    cancelImageDecode();
    input.value = '';
    revokeSourceUrl();
    source.value = null;
    sourceFile.value = null;
    convertedGrid.value = null;
    cancelAnalysis();
    analysisStatus.value = null;
    analysisMessage.value = '';
    error.value = '';
    fileName.value = file.name;
    sourceFile.value = file;
    title.value = file.name.replace(/\.[^.]+$/, '').slice(0, 120) || 'Imported image puzzle';
    const generation = ++decodeGeneration;

    try {
        validateImageLimits(file.size, 1, 1);
        decoding.value = true;
        let raster: RasterImage;
        try {
            raster = await decodeImageInWorker(file, generation);
        } catch {
            if (generation !== decodeGeneration) return;
            raster = await imageFileToRaster(file);
        }
        if (generation !== decodeGeneration) return;
        sourceUrl.value = URL.createObjectURL(file);
        source.value = raster;
    } catch (cause) {
        if (generation !== decodeGeneration) return;
        fileName.value = '';
        sourceFile.value = null;
        revokeSourceUrl();
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
            clearTimeout(decodeTimeout);
            decodeTimeout = undefined;
            worker.terminate();
            if (decodeWorker === worker) decodeWorker = null;
            if (rejectActiveDecode === reject) rejectActiveDecode = null;
        };
        decodeTimeout = setTimeout(() => {
            stopWorker();
            reject(new Error('Image decoding timed out. Try a smaller image.'));
        }, 20_000);
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
        worker.onmessageerror = () => {
            if (generation !== decodeGeneration) return;
            stopWorker();
            reject(new Error('The image decoder returned unreadable pixel data.'));
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
    clearTimeout(decodeTimeout);
    decodeTimeout = undefined;
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

function runAnalysisInMainThread(currentGrid: DraftGridDocument) {
    try {
        if ('mode' in currentGrid) {
            const currentClues = generateColorClues(currentGrid);
            analysisStatus.value = analyzeColorUniqueness(currentGrid.width, currentGrid.height, currentClues.rows, currentClues.columns, currentGrid.palette.length);
        } else {
            const currentClues = generateClues(currentGrid);
            analysisStatus.value = analyzeUniqueness(currentGrid.width, currentGrid.height, currentClues.rows, currentClues.columns);
        }
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
    if (!currentGrid || analyzing.value || previewPending.value || converting.value) return;
    cancelAnalysis();
    const id = ++analysisId;
    analyzing.value = true;
    analysisStatus.value = null;
    analysisMessage.value = '';

    try {
        const worker = new Worker(new URL('../workers/uniqueness.worker.ts', import.meta.url), { type: 'module' });
        analysisWorker = worker;
        worker.onmessage = event => {
            if (event.data.id !== id || id !== analysisId) return;
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
        worker.onmessageerror = () => {
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

watch(standardVersion, () => {
    cancelAnalysis();
    analysisStatus.value = null;
    analysisMessage.value = '';
}, { flush: 'sync' });

watch([source, width, height, threshold, brightness, contrast, invert, cleanupIsolatedCells, fitMode, rotation, cropX, cropY, resizeMethod, importMode, maxColors, whiteTolerance], () => {
    if (conversionTimer) clearTimeout(conversionTimer);
    cancelActiveConversion();
    cancelAnalysis();
    analysisStatus.value = null;
    analysisMessage.value = '';
    convertedGrid.value = null;
    previewPending.value = Boolean(source.value);
    if (!source.value) return;
    conversionTimer = setTimeout(() => {
        conversionTimer = undefined;
        convert();
    }, 180);
}, { flush: 'sync' });

function acceptConversion(converted: DraftGridDocument) {
    validateDraftGrid(converted);
    convertedGrid.value = converted;
    previewPending.value = false;
    analysisStatus.value = null;
    analysisMessage.value = '';
    converting.value = false;
    clearTimeout(conversionTimeout);
    conversionTimeout = undefined;
}

function failConversion(message: string) {
    clearTimeout(conversionTimeout);
    conversionTimeout = undefined;
    error.value = message;
    convertedGrid.value = null;
    previewPending.value = false;
    converting.value = false;
}

function fallbackConversion(
    currentSource: RasterImage,
    targetWidth: number,
    targetHeight: number,
    settings: Parameters<typeof rasterToGrid>[3] | ColorConversionSettings,
) {
    try {
        acceptConversion('maxColors' in settings ? rasterToColorGrid(currentSource, targetWidth, targetHeight, settings) : rasterToGrid(currentSource, targetWidth, targetHeight, settings));
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
    convertedGrid.value = null;
    const id = ++conversionId;
    const targetWidth = width.value;
    const targetHeight = height.value;
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
        ...(importMode.value === 'color' ? { maxColors: maxColors.value, whiteTolerance: whiteTolerance.value } : {}),
    };

    if (typeof Worker !== 'undefined') {
        try {
            const worker = new Worker(new URL('../workers/image-conversion.worker.ts', import.meta.url), { type: 'module' });
            activeWorker = worker;
            conversionTimeout = setTimeout(() => {
                if (id !== conversionId) return;
                cancelActiveConversion();
                failConversion('Image conversion timed out. Reduce the source size and try again.');
            }, 20_000);
            worker.onmessage = event => {
                if (event.data.id !== id || id !== conversionId) return;
                worker.terminate();
                activeWorker = null;
                if (event.data.error) fallbackConversion(currentSource, targetWidth, targetHeight, settings);
                else {
                    try { acceptConversion(event.data.grid as DraftGridDocument); }
                    catch (cause) { failConversion(cause instanceof Error ? cause.message : 'The image could not be converted.'); }
                }
            };
            worker.onerror = () => {
                if (id !== conversionId) return;
                worker.terminate();
                activeWorker = null;
                fallbackConversion(currentSource, targetWidth, targetHeight, settings);
            };
            worker.onmessageerror = () => {
                if (id !== conversionId) return;
                worker.terminate();
                activeWorker = null;
                fallbackConversion(currentSource, targetWidth, targetHeight, settings);
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
        fallbackConversion(currentSource, targetWidth, targetHeight, settings);
    } catch (cause) {
        failConversion(cause instanceof Error ? cause.message : 'The image could not be converted.');
    }
}

function cancelActiveConversion() {
    clearTimeout(conversionTimeout);
    conversionTimeout = undefined;
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
    convertedGrid.value = null;
    previewPending.value = false;
    error.value = 'Image conversion cancelled.';
}

async function createDraftAndEdit() {
    if (!canCreate.value || !grid.value || !sourceFile.value || !source.value) return;
    const submittedGrid = grid.value;
    const submittedFile = sourceFile.value;
    const submittedSource = source.value;
    const submittedTitle = title.value.trim() || 'Imported image puzzle';
    if (!draftStoreUrl || !editorEditUrlTemplate) {
        error.value = 'The draft or editor route is unavailable. Reload this page and try again.';
        return;
    }

    creatingDraft.value = true;
    error.value = '';
    try {
        const canvas = document.createElement('canvas');
        canvas.width = submittedSource.width;
        canvas.height = submittedSource.height;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('A canvas is unavailable to prepare the reveal image.');
        context.putImageData(new ImageData(new Uint8ClampedArray(submittedSource.data), submittedSource.width, submittedSource.height), 0, 0);

        const revealBlob = await new Promise<Blob>((resolve, reject) => {
            canvas.toBlob(image => image ? resolve(image) : reject(new Error('The reveal image could not be prepared.')), 'image/png');
        });
        canvas.width = canvas.height = 0;
        validateImageLimits(revealBlob.size, submittedSource.width, submittedSource.height);
        const form = new FormData();
        form.append('title', submittedTitle);
        if ('mode' in submittedGrid) {
            form.append('grid[mode]', 'color');
            form.append('grid[rules_version]', String(submittedGrid.rules_version));
            submittedGrid.palette.forEach((entry, index) => {
                form.append(`grid[palette][${index}][name]`, entry.name);
                form.append(`grid[palette][${index}][hex]`, entry.hex);
            });
        } else form.append('grid[version]', String(submittedGrid.version));
        form.append('grid[width]', String(submittedGrid.width));
        form.append('grid[height]', String(submittedGrid.height));
        form.append('grid[cells]', submittedGrid.cells);
        form.append('source_image', submittedFile, submittedFile.name);
        form.append('reveal_image', revealBlob, 'reveal-image.png');

        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch(draftStoreUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: form,
        });
        const payload = await readJsonResponse(response) as { id?: number; message?: string };
        if (!response.ok || !Number.isInteger(payload.id) || payload.id! < 1) {
            throw new Error(payload.message ?? 'The imported puzzle draft could not be created.');
        }

        window.location.assign(editorEditUrlTemplate.replace('__PUZZLE_ID__', encodeURIComponent(String(payload.id))));
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : 'The imported puzzle draft could not be created.';
        creatingDraft.value = false;
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
  <main class="feature-page mx-auto max-w-5xl space-y-6 p-6 text-slate-900">
    <header>
      <a :href="editorNewUrl" class="underline">Back to editor</a>
      <h1 class="mt-3 text-3xl font-bold">Import an image</h1>
      <p class="mt-2 max-w-3xl text-slate-700">Choose a PNG, JPEG, or WebP image. The image stays in this browser while you tune the grid. Creating a draft privately saves both the original and a metadata-free reveal copy; the reveal copy appears only after the server verifies that you solved that exact revision.</p>
    </header>

    <section class="feature-panel grid gap-6 rounded-2xl border bg-white p-6 lg:grid-cols-2" aria-label="Image conversion controls">
      <fieldset class="min-w-0 space-y-4" :disabled="creatingDraft">
        <legend class="mb-3 font-semibold">Image and puzzle settings</legend>
        <label class="grid gap-1">Puzzle mode
          <select v-model="importMode" data-import-mode class="min-w-0 rounded border-slate-300">
            <option value="classic">Standard (Classic monochrome)</option>
            <option value="color">Color Picross</option>
          </select>
        </label>
        <template v-if="importMode === 'color'">
          <label class="grid gap-1">Maximum colors
            <select v-model.number="maxColors" class="min-w-0 rounded border-slate-300">
              <option v-for="count in 8" :key="count" :value="count">{{ count }}</option>
            </select>
          </label>
          <label class="grid gap-1">White background tolerance: {{ whiteTolerance }}
            <input v-model.number="whiteTolerance" type="range" min="0" max="255" step="1">
          </label>
          <p class="text-sm text-slate-600">White and transparent areas become empty cells. Increase tolerance to remove more near-white pixels. Other colors are reduced to a palette of up to 8 colors.</p>
          <label class="flex items-center gap-2"><input v-model="standardVersion" data-standard-version type="checkbox"> Create a standard version of this color puzzle</label>
          <p class="text-sm text-slate-600">The standard version keeps empty cells and turns every colored cell black. Switching back restores the color preview. Its clues and uniqueness are checked separately.</p>
        </template>
        <label class="block font-medium">Source image
          <input class="mt-2 block w-full" type="file" accept="image/png,image/jpeg,image/webp" :disabled="converting" @change="chooseImage">
        </label>
        <p class="text-sm text-slate-600">PNG, JPEG or WebP · up to 10 MB and 4 megapixels · grids from 1×1 to 30×30.</p>
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
        <label class="grid gap-1">Puzzle title
          <input v-model="title" type="text" maxlength="120" class="rounded border-slate-300" :disabled="creatingDraft">
        </label>
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
        <label v-if="importMode === 'classic'" class="block">Darkness threshold: <output>{{ threshold }}</output>
          <input v-model.number="threshold" class="mt-2 block w-full" type="range" min="0" max="255" step="1" :disabled="converting">
        </label>
        <label class="grid gap-1">Brightness: {{ brightness }}
          <input v-model.number="brightness" type="range" min="-100" max="100" step="1" :disabled="converting">
        </label>
        <label class="grid gap-1">Contrast: {{ contrast }}
          <input v-model.number="contrast" type="range" min="-100" max="100" step="1" :disabled="converting">
        </label>
        <label class="flex items-center gap-2"><input v-model="cleanupIsolatedCells" type="checkbox" :disabled="converting"> Remove isolated filled cells with no orthogonal filled neighbors</label>
        <label v-if="importMode === 'classic'" class="flex items-center gap-2"><input v-model="invert" type="checkbox" :disabled="converting"> Invert light and dark cells</label>
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
      </fieldset>

      <div class="min-w-0 space-y-4" :aria-busy="converting || previewPending">
        <h2 class="text-xl font-semibold">Pixelated result</h2>
        <p v-if="!grid" class="rounded border border-dashed p-6 text-slate-600">{{ source ? 'Update the preview to see the generated grid. Only an up-to-date preview can be saved.' : 'Select an image and update the preview to see the generated grid.' }}</p>
        <template v-else>
          <p role="status" aria-live="polite">{{ converting ? 'Updating preview… ' : '' }}{{ grid.width }}×{{ grid.height }} · {{ filledCells }} filled {{ filledCells === 1 ? 'cell' : 'cells' }}</p>
          <p v-if="densityWarning" role="status" class="rounded bg-amber-50 p-3 text-amber-900">{{ densityWarning }} This is guidance only; it does not block saving or publishing.</p>
          <div class="max-h-80 overflow-auto rounded border bg-slate-50 p-3">
            <div class="mx-auto grid w-fit" role="img" :aria-label="`Generated ${grid.width} by ${grid.height} ${'mode' in grid ? 'color' : 'monochrome'} grid`" :style="{ gridTemplateColumns: `repeat(${grid.width}, minmax(12px, 20px))` }">
              <span v-for="(cell, index) in grid.cells" :key="index" class="aspect-square border border-slate-300" :class="cell !== '0' ? 'bg-slate-900' : 'bg-white'" :style="{ backgroundColor: previewColor(cell) }" aria-hidden="true"></span>
            </div>
          </div>
          <ul v-if="'mode' in grid" class="flex flex-wrap gap-2" aria-label="Generated color palette">
            <li v-for="(entry, index) in grid.palette" :key="entry.hex" class="flex items-center gap-2 rounded border p-2 text-sm">
              <span class="size-5 rounded border border-slate-400" :style="{ backgroundColor: entry.hex }" aria-hidden="true"></span>
              {{ index + 1 }} · {{ entry.name }} · {{ entry.hex }}
            </li>
          </ul>
          <div v-if="clues" class="grid gap-4 text-sm sm:grid-cols-2">
            <div><h3 class="font-semibold">Row clues</h3><ol class="mt-1 list-inside list-decimal"> <li v-for="(line, index) in clues.rows" :key="index">{{ clueLabel(line) }}</li></ol></div>
            <div><h3 class="font-semibold">Column clues</h3><ol class="mt-1 list-inside list-decimal"><li v-for="(line, index) in clues.columns" :key="index">{{ clueLabel(line) }}</li></ol></div>
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button" :disabled="analyzing || converting || previewPending || creatingDraft" class="rounded border px-4 py-2 font-semibold disabled:opacity-50" @click="analyzeGrid">{{ analyzing ? 'Analyzing uniqueness…' : 'Analyze uniqueness' }}</button>
            <button v-if="analyzing" type="button" class="rounded border px-4 py-2" @click="cancelAnalysis">Cancel analysis</button>
            <button type="button" :disabled="!canCreate" class="rounded border px-4 py-2 font-semibold disabled:opacity-50" @click="createDraftAndEdit">{{ creatingDraft ? 'Creating draft…' : 'Create draft and edit' }}</button>
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
    <p class="text-sm text-slate-600">Review the saved draft in the editor. Server-side validation and uniqueness checks are still required before publication.</p>
  </main>
</template>

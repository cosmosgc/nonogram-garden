<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { generateClues, pointerToCell, resizeGrid, validateGrid, type GridDocument } from '../lib/nonogram/grid';
import { generateColorClues, type ColorGridDocument, type ColorPaletteEntry } from '../lib/nonogram/color-grid';
import { GridHistory, type GridSnapshot } from '../lib/nonogram/history';
import { parseDraftResponse, type DraftGridDocument } from '../lib/nonogram/draft-api';
import { readJsonResponse } from '../lib/nonogram/http';
import { draftCacheKey, encodeDraftCache, parseDraftCache, type DraftCache } from '../lib/nonogram/draft-cache';

const editorRoot = document.getElementById('nonogram-editor');
const editorRoutes = editorRoot?.dataset;
const canvasSize = 480;
const width = ref(10);
const height = ref(10);
const widthInput = ref(10);
const heightInput = ref(10);
let previousWidth = 10;
let previousHeight = 10;
const cells = ref<number[]>(Array(100).fill(0));
const mode = ref<'classic' | 'color'>('classic');
const palette = ref<ColorPaletteEntry[]>([
    { name: 'Crimson', hex: '#C0392B' },
    { name: 'Ocean', hex: '#2471A3' },
]);
const selectedColor = ref(1);
const canvas = ref<HTMLCanvasElement | null>(null);
const title = ref('Untitled puzzle');
const description = ref('');
const tagsInput = ref('');
const tags = computed(() => [...new Set(tagsInput.value.split(',').map(tag => tag.trim().toLowerCase()).filter(Boolean))]);
const tagsError = computed(() => {
    if (tags.value.length > 8) return 'Use no more than 8 tags.';
    if (tags.value.some(tag => tag.length > 24 || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(tag))) return 'Tags must use lowercase letters, numbers, and single hyphens.';
    return '';
});
const validColorPalette = computed(() => palette.value.length > 0 && palette.value.length <= 8
    && palette.value.every(entry => entry.name.trim().length > 0 && entry.name.trim().length <= 40 && /^#[0-9A-F]{6}$/.test(entry.hex))
    && new Set(palette.value.map(entry => entry.hex)).size === palette.value.length);
const testMode = ref(false);
const accessibleView = ref(false);
const playerMarks = ref<number[]>(Array(100).fill(0));
const puzzleId = ref<number | null>(null);
const published = ref(false);
const draftVersion = ref(1);
const saveState = ref('Unsaved');
const cursorX = ref(0);
const cursorY = ref(0);
const history = new GridHistory(100);
const historyRevision = ref(0);
const zoom = ref(1);
const lastSavedAt = ref<string | null>(null);
const tool = ref<'fill' | 'erase' | 'cross'>('fill');
const recovery = ref<DraftCache | null>(null);
const conflicted = ref(false);
let ready = false;
let saving = false;
let saveQueued = false;
let autosaveTimer: ReturnType<typeof setTimeout> | undefined;
let paintValue = 1;
let activePointer: number | null = null;
let strokeBefore: GridSnapshot | null = null;
let hydrating = false;

/**
 * Applies server or cached state without scheduling an autosave for that change.
 * Vue flushes watchers after the assignment, so the guard is cleared on the next
 * tick rather than at the end of this call.
 */
function hydrate(apply: () => void): void {
    hydrating = true;
    apply();
    nextTick(() => { hydrating = false; });
}

function configuredRoute(value: string | undefined): string {
    if (!value) throw new Error('Editor route configuration is missing. Reload this page and try again.');
    return value;
}

function puzzleRoute(template: string | undefined, id: number): string {
    return configuredRoute(template).replace('__PUZZLE_ID__', encodeURIComponent(String(id)));
}

const grid = computed<DraftGridDocument>(() => mode.value === 'color' ? ({
    mode: 'color', rules_version: 1, width: width.value, height: height.value,
    cells: cells.value.join(''), palette: palette.value.map(entry => ({ ...entry })),
}) : ({ version: 1, width: width.value, height: height.value, cells: cells.value.join('') }));
const clues = computed(() => mode.value === 'color'
    ? { mode: 'color' as const, ...(validColorPalette.value ? generateColorClues(grid.value as ColorGridDocument) : { rows: Array.from({ length: height.value }, () => []), columns: Array.from({ length: width.value }, () => []) }) }
    : { mode: 'classic' as const, ...generateClues(grid.value as GridDocument) });
const activeIndex = computed(() => cursorY.value * width.value + cursorX.value);
const activeCellDescription = computed(() => {
    const mark = testMode.value ? playerMarks.value[activeIndex.value] : cells.value[activeIndex.value];
    return `Row ${cursorY.value + 1}, column ${cursorX.value + 1}, ${mark > 0 ? (mode.value === 'color' ? `filled ${palette.value[mark - 1]?.name ?? 'colour'}` : 'filled') : mark === 2 ? 'crossed out' : 'empty'}`;
});
const testSolved = computed(() => testMode.value && playerMarks.value.every(mark => mark !== 0)
    && cells.value.every((solution, index) => solution === 0
        ? playerMarks.value[index] === 0 || playerMarks.value[index] === (mode.value === 'color' ? 9 : 2)
        : playerMarks.value[index] === solution));
const canUndo = computed(() => { historyRevision.value; return history.canUndo; });
const canRedo = computed(() => { historyRevision.value; return history.canRedo; });

function cacheKey(id = puzzleId.value): string {
    const userId = Number(document.getElementById('nonogram-editor')?.dataset.userId);
    return draftCacheKey(id, userId);
}

function readRecoveryCache(id = puzzleId.value): DraftCache | null {
    try { return parseDraftCache(localStorage.getItem(cacheKey(id))); } catch { return null; }
}

function removeRecoveryCache(id = puzzleId.value) {
    try { localStorage.removeItem(cacheKey(id)); } catch { /* Storage may be disabled; remote saving still works. */ }
}

function snapshot(): GridSnapshot {
    return { width: width.value, height: height.value, cells: [...cells.value] };
}

function restore(state: GridSnapshot) {
    width.value = widthInput.value = previousWidth = state.width;
    height.value = heightInput.value = previousHeight = state.height;
    cells.value = [...state.cells];
    cursorX.value = Math.min(cursorX.value, width.value - 1);
    cursorY.value = Math.min(cursorY.value, height.value - 1);
}

function draw() {
    const element = canvas.value;
    const context = element?.getContext('2d');
    if (!element || !context) return;
    const pixelRatio = Math.max(1, window.devicePixelRatio || 1);
    const renderScale = pixelRatio * zoom.value;
    element.width = Math.round(canvasSize * renderScale);
    element.height = Math.round(canvasSize * renderScale);
    context.setTransform(renderScale, 0, 0, renderScale, 0, 0);
    const cellWidth = canvasSize / width.value;
    const cellHeight = canvasSize / height.value;
    context.clearRect(0, 0, canvasSize, canvasSize);
    for (let y = 0; y < height.value; y++) {
        for (let x = 0; x < width.value; x++) {
            const index = y * width.value + x;
            const mark = testMode.value ? playerMarks.value[index] : cells.value[index];
            if (mode.value === 'color' && testMode.value && mark === 9 || mode.value === 'classic' && mark === 2) {
                context.beginPath();
                context.strokeStyle = '#9a3d3d';
                context.lineWidth = 2;
                context.moveTo(x * cellWidth + 4, y * cellHeight + 4);
                context.lineTo((x + 1) * cellWidth - 4, (y + 1) * cellHeight - 4);
                context.moveTo((x + 1) * cellWidth - 4, y * cellHeight + 4);
                context.lineTo(x * cellWidth + 4, (y + 1) * cellHeight - 4);
                context.stroke();
            } else if (mark > 0) {
                context.fillStyle = mode.value === 'color' ? (palette.value[mark - 1]?.hex ?? '#222b25') : '#222b25';
                context.fillRect(x * cellWidth, y * cellHeight, cellWidth, cellHeight);
                if (mode.value === 'color') {
                    context.strokeStyle = 'rgba(255,255,255,.65)';
                    context.lineWidth = 1;
                    context.beginPath();
                    const pattern = (mark - 1) % 4;
                    if (pattern === 0) {
                        context.moveTo(x * cellWidth + 2, y * cellHeight + cellHeight - 2);
                        context.lineTo(x * cellWidth + cellWidth - 2, y * cellHeight + 2);
                    } else if (pattern === 1) {
                        context.moveTo(x * cellWidth + 2, y * cellHeight + 2);
                        context.lineTo(x * cellWidth + cellWidth - 2, y * cellHeight + cellHeight - 2);
                    } else if (pattern === 2) {
                        context.moveTo(x * cellWidth + cellWidth / 2, y * cellHeight + 2);
                        context.lineTo(x * cellWidth + cellWidth / 2, y * cellHeight + cellHeight - 2);
                    } else {
                        context.moveTo(x * cellWidth + 2, y * cellHeight + cellHeight / 2);
                        context.lineTo(x * cellWidth + cellWidth - 2, y * cellHeight + cellHeight / 2);
                    }
                    context.stroke();
                }
            }
        }
    }
    context.strokeStyle = '#2478c8';
    context.lineWidth = 2;
    context.strokeRect(cursorX.value * cellWidth + 1, cursorY.value * cellHeight + 1, cellWidth - 2, cellHeight - 2);
    context.beginPath();
    context.strokeStyle = '#bbc6bc';
    context.lineWidth = 1;
    for (let x = 0; x <= width.value; x++) {
        const px = Math.round(x * cellWidth) + 0.5;
        context.moveTo(px, 0); context.lineTo(px, canvasSize);
    }
    for (let y = 0; y <= height.value; y++) {
        const py = Math.round(y * cellHeight) + 0.5;
        context.moveTo(0, py); context.lineTo(canvasSize, py);
    }
    context.stroke();
}

function cellAt(event: PointerEvent): number | null {
    const element = canvas.value;
    if (!element) return null;
    const rect = element.getBoundingClientRect();
    const point = pointerToCell(event.clientX, event.clientY, rect, width.value, height.value);
    if (!point) return null;
    cursorX.value = point.x;
    cursorY.value = point.y;
    return point.y * width.value + point.x;
}

function paintAt(event: PointerEvent) {
    if (activePointer !== event.pointerId) return;
    const index = cellAt(event);
    if (index === null) return;
    if (testMode.value) {
        if (playerMarks.value[index] === paintValue) return;
        playerMarks.value[index] = paintValue;
        playerMarks.value = [...playerMarks.value];
        draw();
        saveState.value = testSolved.value ? 'Test solved!' : 'Test in progress';
        return;
    }
    if (cells.value[index] === paintValue) return;
    cells.value[index] = paintValue;
    cells.value = [...cells.value];
    draw();
    saveState.value = 'Unsaved changes';
}

function startStroke(event: PointerEvent) {
    const element = canvas.value;
    const index = cellAt(event);
    if (!element || index === null) return;
    event.preventDefault();
    element.setPointerCapture(event.pointerId);
    activePointer = event.pointerId;
    strokeBefore = snapshot();
    paintValue = tool.value === 'erase' ? 0 : tool.value === 'cross' ? 9 : mode.value === 'color' ? selectedColor.value : 1;
    paintAt(event);
}

function endStroke(event: PointerEvent) {
    if (activePointer !== event.pointerId) return;
    if (canvas.value?.hasPointerCapture?.(event.pointerId)) canvas.value.releasePointerCapture(event.pointerId);
    if (strokeBefore && !testMode.value) history.record(strokeBefore, snapshot());
    historyRevision.value++;
    strokeBefore = null;
    activePointer = null;
}

function accessibleCellLabel(index: number): string {
    const mark = testMode.value ? playerMarks.value[index] : cells.value[index];
    const row = Math.floor(index / width.value) + 1;
    const column = index % width.value + 1;
    return `Row ${row}, column ${column}: ${mark === 9 ? 'crossed out' : mark > 0 ? (mode.value === 'color' ? `filled ${palette.value[mark - 1]?.name ?? 'colour'}` : 'filled') : mark === 2 ? 'crossed out' : 'empty'}`;
}

function accessibleCellMark(index: number): string {
    const mark = testMode.value ? playerMarks.value[index] : cells.value[index];
    return mark === 9 ? 'crossed out' : mark > 0 ? (mode.value === 'color' ? `filled ${palette.value[mark - 1]?.name ?? 'colour'}` : 'filled') : mark === 2 ? 'crossed out' : 'empty';
}

function clueLabel(line: number[] | [number, number][], clueMode: 'classic' | 'color'): string {
    if (clueMode === 'classic') return (line as number[]).join(' ') || '0';
    return (line as [number, number][]).map(([length, colorId]) => `${length} ${palette.value[colorId - 1]?.name ?? `color ${colorId}`}`).join(', ') || 'empty';
}

function accessibleClueLabel(line: number[] | [number, number][], clueMode: 'classic' | 'color'): string {
    if (clueMode === 'classic') {
        const clues = line as number[];

        return clues.length === 0 || (clues.length === 1 && clues[0] === 0) ? 'empty' : clues.join(' ');
    }

    return clueLabel(line, clueMode);
}

function colorClueSymbol(colorId: number): string {
    return ['●', '◆', '▲', '■', '✚', '⬟', '★', '⬢'][colorId - 1] ?? '●';
}

function accessibleCellGlyph(index: number): string {
    const label = accessibleCellMark(index);
    if (label === 'crossed out') return '×';
    if (label.startsWith('filled')) return mode.value === 'color' ? `${cells.value[index]}` : '■';
    return '□';
}

function selectColor(id: number) {
    selectedColor.value = id;
    tool.value = 'fill';
}

function focusAccessibleCell(index: number) {
    nextTick(() => document.querySelector<HTMLButtonElement>(`[data-accessible-cell="${index}"]`)?.focus());
}

function handleAccessibleKey(index: number, event: KeyboardEvent) {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        paintAccessibleCell(index);
        return;
    }
    if (!['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp'].includes(event.key)) return;
    event.preventDefault();
    const x = index % width.value;
    const y = Math.floor(index / width.value);
    const nextX = Math.max(0, Math.min(width.value - 1, x + (event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0)));
    const nextY = Math.max(0, Math.min(height.value - 1, y + (event.key === 'ArrowDown' ? 1 : event.key === 'ArrowUp' ? -1 : 0)));
    if (nextX === x && nextY === y) return;
    const nextIndex = nextY * width.value + nextX;
    cursorX.value = nextX;
    cursorY.value = nextY;
    focusAccessibleCell(nextIndex);
}

function paintAccessibleCell(index: number) {
    cursorX.value = index % width.value;
    cursorY.value = Math.floor(index / width.value);
    const value = tool.value === 'erase' ? 0 : tool.value === 'cross' && testMode.value ? (mode.value === 'color' ? 9 : 2) : mode.value === 'color' ? selectedColor.value : 1;
    if (testMode.value) {
        if (playerMarks.value[index] === value) return;
        playerMarks.value[index] = value;
        playerMarks.value = [...playerMarks.value];
        saveState.value = testSolved.value ? 'Test solved!' : 'Test in progress';
        draw();
        return;
    }
    if (cells.value[index] === value) return;
    const before = snapshot();
    cells.value[index] = value;
    cells.value = [...cells.value];
    history.record(before, snapshot());
    historyRevision.value++;
    saveState.value = 'Unsaved changes';
    draw();
}

function clearGrid() {
    if (testMode.value) {
        playerMarks.value = Array(width.value * height.value).fill(0);
        saveState.value = 'Test in progress';
        draw();
        return;
    }
    const before = snapshot();
    const cleared = Array(width.value * height.value).fill(0);
    cells.value = cleared;
    history.record(before, snapshot());
    historyRevision.value++;
    saveState.value = 'Unsaved changes';
    draw();
}

function undo() {
    const previous = history.undo(snapshot());
    historyRevision.value++;
    if (previous) {
        restore(previous);
        saveState.value = 'Unsaved changes';
        draw();
    }
}

function redo() {
    const next = history.redo(snapshot());
    historyRevision.value++;
    if (next) {
        restore(next);
        saveState.value = 'Unsaved changes';
        draw();
    }
}

function handleGridKey(event: KeyboardEvent) {
    const dx = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
    const dy = event.key === 'ArrowDown' ? 1 : event.key === 'ArrowUp' ? -1 : 0;
    if (dx || dy) {
        event.preventDefault();
        cursorX.value = Math.max(0, Math.min(width.value - 1, cursorX.value + dx));
        cursorY.value = Math.max(0, Math.min(height.value - 1, cursorY.value + dy));
        draw();
    } else if (event.key === ' ' || event.key === 'Enter') {
        event.preventDefault();
        if (testMode.value) {
            playerMarks.value[activeIndex.value] = tool.value === 'erase' ? 0 : tool.value === 'cross' ? (mode.value === 'color' ? 9 : 2) : mode.value === 'color' ? selectedColor.value : 1;
            playerMarks.value = [...playerMarks.value];
            saveState.value = testSolved.value ? 'Test solved!' : 'Test in progress';
        } else {
            const before = snapshot();
            cells.value[activeIndex.value] = tool.value === 'erase' ? 0 : mode.value === 'color' ? selectedColor.value : 1;
            cells.value = [...cells.value];
            history.record(before, snapshot());
            historyRevision.value++;
            saveState.value = 'Unsaved changes';
        }
        draw();
    }
}

function handleShortcuts(event: KeyboardEvent) {
    const target = event.target as HTMLElement | null;
    if (testMode.value || (target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        if (event.shiftKey) redo(); else undo();
    } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'y') {
        event.preventDefault();
        redo();
    }
}

function applyResize() {
    width.value = Math.max(1, Math.min(30, Math.floor(widthInput.value || 1)));
    height.value = Math.max(1, Math.min(30, Math.floor(heightInput.value || 1)));
    widthInput.value = width.value;
    heightInput.value = height.value;
    const before: GridSnapshot = { width: previousWidth, height: previousHeight, cells: [...cells.value] };
    const resized = resizeGrid({
        version: 1, width: previousWidth, height: previousHeight,
        cells: cells.value.map(cell => cell > 0 ? '1' : '0').join(''),
    }, width.value, height.value);
    const resizedCells = Array(width.value * height.value).fill(0);
    for (let y = 0; y < Math.min(previousHeight, height.value); y++) {
        for (let x = 0; x < Math.min(previousWidth, width.value); x++) {
            resizedCells[y * width.value + x] = cells.value[y * previousWidth + x];
        }
    }
    previousWidth = width.value;
    previousHeight = height.value;
    cells.value = mode.value === 'color' ? resizedCells : [...resized.cells].map(Number);
    history.record(before, snapshot());
    historyRevision.value++;
    cursorX.value = Math.min(cursorX.value, width.value - 1);
    cursorY.value = Math.min(cursorY.value, height.value - 1);
    saveState.value = 'Unsaved changes';
    nextTick(draw);
}

function preset(value: number) {
    widthInput.value = value;
    heightInput.value = value;
    applyResize();
}

function setMode(nextMode: 'classic' | 'color') {
    if (mode.value === nextMode || testMode.value) return;
    mode.value = nextMode;
    cells.value = cells.value.map(cell => nextMode === 'classic' ? (cell > 0 ? 1 : 0) : Math.min(cell, palette.value.length));
    selectedColor.value = Math.min(selectedColor.value, palette.value.length);
    saveState.value = 'Unsaved changes';
    draw();
}

function addPaletteColor() {
    if (palette.value.length >= 8) return;
    const defaults = ['#C0392B', '#2471A3', '#239B56', '#8E44AD', '#D68910', '#148F77', '#CA6F1E', '#566573'];
    const index = palette.value.length;
    palette.value = [...palette.value, { name: `Color ${index + 1}`, hex: defaults[index] }];
    selectedColor.value = palette.value.length;
    saveState.value = 'Unsaved changes';
}

function removePaletteColor(index: number) {
    if (palette.value.length <= 1) return;
    const removedId = index + 1;
    palette.value = palette.value.filter((_, paletteIndex) => paletteIndex !== index);
    cells.value = cells.value.map(cell => cell === removedId ? 0 : cell > removedId ? cell - 1 : cell);
    selectedColor.value = Math.max(1, Math.min(selectedColor.value > removedId ? selectedColor.value - 1 : selectedColor.value, palette.value.length));
    saveState.value = 'Unsaved changes';
    draw();
}

function hydrateGrid(document: DraftGridDocument) {
    width.value = widthInput.value = previousWidth = document.width;
    height.value = heightInput.value = previousHeight = document.height;
    cells.value = [...document.cells].map(Number);
    if ('mode' in document) {
        mode.value = 'color';
        palette.value = document.palette.map(entry => ({ ...entry }));
        selectedColor.value = Math.min(selectedColor.value, palette.value.length);
    } else {
        mode.value = 'classic';
    }
}

function startTest() {
    testMode.value = true;
    playerMarks.value = Array(width.value * height.value).fill(0);
    tool.value = 'fill';
    saveState.value = 'Test mode: solution hidden';
    draw();
}

function stopTest() {
    testMode.value = false;
    tool.value = 'fill';
    saveState.value = 'Editor mode';
    draw();
}

function writeRecoveryCache(serverVersion = draftVersion.value) {
    try {
        const record: DraftCache = {
            schemaVersion: 1,
            puzzleId: puzzleId.value,
            serverVersion,
            title: title.value,
            description: description.value,
            tags: [...tags.value],
            grid: { ...grid.value, ...('palette' in grid.value ? { palette: grid.value.palette.map(entry => ({ ...entry })) } : {}) },
            savedAt: Date.now(),
        };
        localStorage.setItem(cacheKey(), encodeDraftCache(record));
    } catch {
        saveState.value = 'Unsaved changes (local recovery unavailable)';
    }
}

function scheduleAutosave() {
    if (!ready || conflicted.value) return;
    if (autosaveTimer) clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(() => { void saveDraft(true); }, 800);
}

watch(zoom, () => nextTick(draw));

watch([title, description, tagsInput, grid], () => {
    if (!ready || hydrating) return;
    saveState.value = 'Unsaved changes';
    writeRecoveryCache();
    if (conflicted.value) {
        recovery.value = readRecoveryCache();
        return;
    }
    scheduleAutosave();
});

async function saveDraft(automatic = false) {
    if (tagsError.value) { saveState.value = tagsError.value; return; }
    if (mode.value === 'color' && !validColorPalette.value) { saveState.value = 'Fix the color palette before saving'; return; }
    if (conflicted.value) return;
    if (saving) { saveQueued = true; return; }
    if (autosaveTimer) clearTimeout(autosaveTimer);
    const submittedTitle = title.value;
    const submittedDescription = description.value;
    const submittedTags = [...tags.value];
    const submittedGrid = { ...grid.value };
    const submittedPuzzleId = puzzleId.value;
    const submittedVersion = draftVersion.value;
    saving = true;
    saveState.value = automatic ? 'Autosaving…' : 'Saving…';
    writeRecoveryCache(submittedVersion);
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    try {
        const endpoint = submittedPuzzleId
            ? puzzleRoute(editorRoutes?.draftUpdateUrlTemplate, submittedPuzzleId)
            : configuredRoute(editorRoutes?.draftStoreUrl);
        const response = await fetch(endpoint, {
            method: submittedPuzzleId ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token ?? '' },
            body: JSON.stringify({
                ...(submittedPuzzleId ? { version: submittedVersion } : {}),
                title: submittedTitle,
                description: submittedDescription,
                tags: submittedTags,
                grid: submittedGrid,
            }),
        });
        const body = await readJsonResponse(response);
        if (response.status === 409) {
            conflicted.value = true;
            recovery.value = readRecoveryCache(submittedPuzzleId);
            saveState.value = 'Conflict: recover local edits or reload the newer draft';
            return;
        }
        if (!response.ok) throw new Error(body.message ?? 'Save failed');
        const payload = parseDraftResponse(body);
        puzzleId.value = payload.id;
        draftVersion.value = payload.version;
        lastSavedAt.value = payload.updated_at ? new Date(payload.updated_at).toLocaleTimeString() : new Date().toLocaleTimeString();
        window.history.replaceState({}, '', puzzleRoute(editorRoutes?.editorEditUrlTemplate, payload.id));
        if (!submittedPuzzleId) removeRecoveryCache(null);
        const stillCurrent = title.value === submittedTitle && description.value === submittedDescription
            && JSON.stringify(tags.value) === JSON.stringify(submittedTags) && JSON.stringify(grid.value) === JSON.stringify(submittedGrid);
        if (stillCurrent) {
            removeRecoveryCache(puzzleId.value);
            saveState.value = 'Saved';
        } else {
            writeRecoveryCache(draftVersion.value);
            saveState.value = 'Unsaved changes';
            saveQueued = true;
        }
    } catch (error) {
        writeRecoveryCache(submittedVersion);
        saveState.value = error instanceof Error ? `${error.message} — edits kept locally` : 'Save failed — edits kept locally';
    } finally {
        saving = false;
        if (saveQueued && !conflicted.value) {
            saveQueued = false;
            scheduleAutosave();
        }
    }
}

function applyRecovery() {
    const cached = recovery.value;
    if (!cached || !window.confirm('Restore this local copy over the current server draft?')) return;
    hydrate(() => {
        title.value = cached.title;
        description.value = cached.description;
        tagsInput.value = (cached.tags ?? []).join(', ');
        hydrateGrid(cached.grid);
    });
    history.clear();
    historyRevision.value++;
    recovery.value = null;
    conflicted.value = false;
    ready = true;
    saveState.value = 'Recovered local edits';
    writeRecoveryCache();
    nextTick(() => { draw(); scheduleAutosave(); });
}

function restoreCache(cached: DraftCache) {
    hydrate(() => {
        title.value = cached.title;
        description.value = cached.description;
        tagsInput.value = (cached.tags ?? []).join(', ');
        hydrateGrid(cached.grid);
    });
    history.clear();
    historyRevision.value++;
}

async function publishPuzzle() {
    if (!puzzleId.value || saveState.value !== 'Saved') {
        saveState.value = 'Save all edits before publishing';
        return;
    }
    if (!window.confirm('Publish this puzzle publicly? The published answer will be immutable.')) return;
    saveState.value = 'Checking uniqueness and publishing…';
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    try {
        const response = await fetch(puzzleRoute(editorRoutes?.publishUrlTemplate, puzzleId.value), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token ?? '' },
            body: JSON.stringify({ confirm: true }),
        });
        const result = await readJsonResponse(response);
        if (!response.ok) {
            saveState.value = result.message ?? `Publish failed (${result.uniqueness ?? response.status})`;
            return;
        }
        published.value = true;
        saveState.value = 'Published';
        window.location.assign(puzzleRoute(editorRoutes?.puzzleUrlTemplate, puzzleId.value));
    } catch (error) {
        saveState.value = error instanceof Error ? error.message : 'Publish failed';
    }
}

async function loadDraft(id: number) {
    saveState.value = 'Loading…';
    try {
        const response = await fetch(puzzleRoute(editorRoutes?.draftShowUrlTemplate, id), { headers: { 'Accept': 'application/json' } });
        if (!response.ok) throw new Error(response.status === 403 ? 'You cannot edit this draft.' : 'Could not load the draft.');
        const payload = parseDraftResponse(await readJsonResponse(response));
        const loaded = payload.grid;
        if ('mode' in loaded) generateColorClues(loaded);
        else generateClues(loaded);
        hydrate(() => {
            puzzleId.value = payload.id;
            draftVersion.value = payload.version;
            lastSavedAt.value = payload.updated_at ? new Date(payload.updated_at).toLocaleTimeString() : null;
            title.value = payload.title;
            description.value = payload.description ?? '';
            tagsInput.value = (payload.tags ?? []).join(', ');
            hydrateGrid(loaded);
        });
        history.clear();
        historyRevision.value++;
        const cached = readRecoveryCache(id);
        if (cached && cached.serverVersion === payload.version) {
            restoreCache(cached);
            saveState.value = 'Recovered unsaved local edits';
            ready = true;
            writeRecoveryCache(payload.version);
            scheduleAutosave();
        } else if (cached) {
            recovery.value = cached;
            conflicted.value = true;
            saveState.value = 'A local copy is available; server draft has changed';
            ready = true;
        } else {
            ready = true;
            saveState.value = 'Saved';
        }
        nextTick(draw);
    } catch (error) {
        saveState.value = error instanceof Error ? error.message : 'Could not load the draft.';
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleShortcuts);
    const importRequested = new URLSearchParams(window.location.search).get('image_import') === '1';
    const userId = Number(editorRoot?.dataset.userId);
    if (importRequested) {
        try {
            const key = `nonogram:image-import:${userId}`;
            const raw = sessionStorage.getItem(key);
            if (raw) {
                const imported = JSON.parse(raw) as GridDocument;
                validateGrid(imported);
                sessionStorage.removeItem(key);
                hydrate(() => {
                    width.value = widthInput.value = previousWidth = imported.width;
                    height.value = heightInput.value = previousHeight = imported.height;
                    cells.value = [...imported.cells].map(Number);
                    title.value = 'Imported image puzzle';
                    description.value = '';
                });
                playerMarks.value = Array(imported.width * imported.height).fill(0);
                history.clear();
                historyRevision.value++;
                cursorX.value = cursorY.value = 0;
                saveState.value = 'Image grid imported. Save to create a draft.';
                window.history.replaceState({}, '', configuredRoute(editorRoutes?.editorNewUrl));
                nextTick(() => {
                    ready = true;
                    draw();
                });
                return;
            }
        } catch {
            saveState.value = 'The imported grid could not be restored. Please convert the image again.';
        }
    }
    const id = Number(editorRoot?.dataset.puzzleId);
    if (Number.isInteger(id) && id > 0) {
        void loadDraft(id);
    } else {
        const cached = readRecoveryCache(null);
        if (cached) {
            restoreCache(cached);
            saveState.value = 'Recovered local draft';
        }
        ready = true;
        draw();
        if (cached) scheduleAutosave();
    }
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleShortcuts);
    if (autosaveTimer) clearTimeout(autosaveTimer);
});
</script>

<template>
  <main class="editor-page">
    <header class="editor-header">
      <div><a :href="configuredRoute(editorRoutes?.homeUrl)">← Home</a><h1>Nonogram editor</h1><p>Create a private puzzle draft.</p><a class="underline" :href="configuredRoute(editorRoutes?.importUrl)">Import from image</a></div>
      <div class="save-area"><span role="status">{{ mode === 'color' ? 'Color drafts are not publishable yet. ' : '' }}{{ saveState }}<small v-if="lastSavedAt">Last saved {{ lastSavedAt }}</small></span><button v-if="recovery" @click="applyRecovery">Restore local copy</button><button v-if="puzzleId && !published" :disabled="mode === 'color' || conflicted || saveState !== 'Saved'" @click="publishPuzzle">Publish</button><button class="primary" :disabled="conflicted || published" @click="saveDraft()">Save draft</button></div>
    </header>
    <section class="editor-layout">
      <aside class="settings">
        <label>Title<input v-model="title" maxlength="120" :disabled="testMode" @input="saveState = 'Unsaved changes'"></label>
        <label>Description<textarea v-model="description" maxlength="2000" rows="3" :disabled="testMode" @input="saveState = 'Unsaved changes'"></textarea></label>
        <label>Tags (comma-separated)<input v-model="tagsInput" maxlength="240" :disabled="testMode" aria-describedby="puzzle-tags-help"></label>
        <p id="puzzle-tags-help" class="text-xs">Up to 8 tags. Use lowercase letters, numbers, and hyphens, for example: animals, ocean-life.</p>
        <p v-if="tagsError" role="alert" class="text-sm text-red-700">{{ tagsError }}</p>
        <fieldset class="mode-picker" :disabled="testMode">
          <legend>Puzzle rules</legend>
          <button type="button" :aria-pressed="mode === 'classic'" @click="setMode('classic')">Classic</button>
          <button type="button" :aria-pressed="mode === 'color'" @click="setMode('color')">Color Picross</button>
        </fieldset>
        <fieldset v-if="mode === 'color'" class="palette-controls" :disabled="testMode">
          <legend>Color palette <span>({{ palette.length }}/8)</span></legend>
          <p>Cell patterns and colour names help distinguish colours beyond hue.</p>
          <div v-for="(entry, index) in palette" :key="index" class="palette-entry">
          <button type="button" class="palette-select" :aria-label="`Select ${entry.name || `color ${index + 1}`} for painting`" :aria-pressed="selectedColor === index + 1" @click="selectColor(index + 1)" :style="{ '--palette-color': entry.hex }"><span aria-hidden="true">{{ index + 1 }}</span></button>
            <label><span class="sr-only">Color {{ index + 1 }} name</span><input v-model="entry.name" maxlength="40" :aria-label="`Color ${index + 1} name`" placeholder="Color name"></label>
            <label><span class="sr-only">Color {{ index + 1 }} hex</span><input v-model="entry.hex" maxlength="7" :aria-label="`Color ${index + 1} hex value`" placeholder="#RRGGBB"></label>
            <button type="button" :aria-label="`Remove ${entry.name || `color ${index + 1}`}`" :disabled="palette.length <= 1" @click="removePaletteColor(index)">Remove</button>
          </div>
          <button type="button" :disabled="palette.length >= 8" @click="addPaletteColor">Add color</button>
          <p v-if="!validColorPalette" role="alert">Use unique uppercase #RRGGBB values and non-empty names up to 40 characters.</p>
        </fieldset>
        <div class="test-controls"><button v-if="!testMode" @click="startTest">Test play</button><button v-else @click="stopTest">Exit test</button><span v-if="testMode" role="status">{{ testSolved ? 'Correct solution!' : 'Test mode: solution hidden' }}</span></div>
        <div class="zoom-controls" role="group" aria-label="Grid zoom"><span>Zoom {{ Math.round(zoom * 100) }}%</span><button aria-label="Zoom out" :disabled="zoom <= 0.5" @click="zoom = Math.max(0.5, zoom - 0.1)">−</button><button aria-label="Zoom in" :disabled="zoom >= 2" @click="zoom = Math.min(2, zoom + 0.1)">+</button></div>
        <label>Width<input v-model.number="widthInput" type="number" min="1" max="30" :disabled="testMode" @change="applyResize"></label>
        <label>Height<input v-model.number="heightInput" type="number" min="1" max="30" :disabled="testMode" @change="applyResize"></label>
        <fieldset><legend>Presets</legend><button v-for="n in [5, 10, 15, 20, 25]" :key="n" :disabled="testMode" @click="preset(n)">{{ n }}×{{ n }}</button></fieldset>
        <div class="tool-group" role="group" aria-label="Cell paint tool">
          <button :aria-pressed="tool === 'fill'" @click="tool = 'fill'">Fill</button>
          <button :aria-pressed="tool === 'erase'" @click="tool = 'erase'">Erase</button>
          <button v-if="testMode" :aria-pressed="tool === 'cross'" @click="tool = 'cross'">X mark</button>
        </div>
        <div class="history-tools" role="group" aria-label="Edit history">
          <button :disabled="testMode || !canUndo" @click="undo">Undo</button>
          <button :disabled="testMode || !canRedo" @click="redo">Redo</button>
        </div>
        <button @click="clearGrid">{{ testMode ? 'Clear test marks' : 'Clear grid' }}</button>
        <p>Use arrows to navigate and Space or Enter to paint. Ctrl/Cmd+Z undoes; Ctrl/Cmd+Y redoes.</p>
      </aside>
      <div class="board-area">
        <button class="accessible-toggle" :aria-expanded="accessibleView" @click="accessibleView = !accessibleView">{{ accessibleView ? 'Hide accessible cell grid' : 'Show accessible cell grid' }}</button>
        <div v-if="accessibleView" class="accessible-grid-wrap">
          <table class="accessible-grid" aria-label="Nonogram cell editor">
            <thead><tr><th scope="col">Row clues</th><th v-for="(line, x) in clues.columns" :key="x" scope="col">Column {{ x + 1 }} clue: {{ accessibleClueLabel(line, clues.mode) }}</th></tr></thead>
            <tbody><tr v-for="(line, y) in clues.rows" :key="y">
              <th scope="row">Row {{ y + 1 }} clue: {{ accessibleClueLabel(line, clues.mode) }}</th>
              <td v-for="x in width" :key="x"><button type="button" :data-accessible-cell="y * width + x - 1"
                :tabindex="activeIndex === y * width + x - 1 ? 0 : -1"
                :aria-label="accessibleCellLabel(y * width + x - 1)"
                :aria-pressed="(testMode ? playerMarks[y * width + x - 1] : cells[y * width + x - 1]) !== 0"
                @click="paintAccessibleCell(y * width + x - 1)"
                @keydown="handleAccessibleKey(y * width + x - 1, $event)">{{ accessibleCellGlyph(y * width + x - 1) }}</button></td>
            </tr></tbody>
          </table>
        </div>
        <div class="clues-and-board" :aria-hidden="accessibleView">
          <div class="column-clues" :style="{ width: `${480 * zoom}px` }" aria-label="Column clues">
            <div v-for="(line, x) in clues.columns" :key="x" class="clue-line">
              <template v-if="clues.mode === 'classic'"><span v-for="(clue, i) in line as number[]" :key="i">{{ clue }}</span></template>
              <template v-else><span v-for="(clue, i) in line as [number, number][]" :key="i" :title="palette[clue[1] - 1]?.name" :style="{ color: palette[clue[1] - 1]?.hex }">{{ clue[0] }}{{ colorClueSymbol(clue[1]) }}</span></template>
            </div>
          </div>
          <div class="row-and-board">
            <div class="row-clues" aria-label="Row clues">
              <div v-for="(line, y) in clues.rows" :key="y" class="clue-line">
                <template v-if="clues.mode === 'classic'">{{ clueLabel(line, 'classic') }}</template>
                <template v-else><span v-for="(clue, index) in line as [number, number][]" :key="index" :title="palette[clue[1] - 1]?.name" :style="{ color: palette[clue[1] - 1]?.hex }">{{ clue[0] }}{{ colorClueSymbol(clue[1]) }} </span><span v-if="line.length === 0">empty</span></template>
              </div>
            </div>
            <canvas ref="canvas" width="480" height="480" :style="{ width: `${480 * zoom}px`, height: `${480 * zoom}px` }" :tabindex="accessibleView ? -1 : 0" :aria-hidden="accessibleView" role="grid"
              :aria-rowcount="height" :aria-colcount="width" :aria-label="`${testMode ? 'Test play, solution hidden. ' : ''}Puzzle grid. ${activeCellDescription}. Use arrow keys to move and Space to paint.`"
              @keydown="handleGridKey" @pointerdown="startStroke" @pointermove="paintAt" @pointerup="endStroke" @pointercancel="endStroke" @pointerleave="endStroke"></canvas>
          </div>
        </div>
      </div>
    </section>
  </main>
</template>

<style>
.editor-page{max-width:1200px;margin:0 auto;padding:24px;color:#202820}.editor-header,.save-area{display:flex;justify-content:space-between;align-items:center;gap:18px}.editor-header h1{margin:8px 0}.editor-header p,.settings p{color:#647067}.save-area button,.settings button{padding:9px 12px;border:1px solid #9aa69c;border-radius:6px;background:#fff;cursor:pointer}.settings button[aria-pressed="true"]{background:#21372a;color:#fff}.settings button:disabled{opacity:.45;cursor:not-allowed}.save-area .primary{background:#21372a;color:#fff}.editor-layout{display:grid;grid-template-columns:220px minmax(0,1fr);gap:28px;margin-top:28px}.settings{display:flex;flex-direction:column;gap:12px}.settings label{display:grid;gap:5px}.settings input,.settings textarea{padding:8px;border:1px solid #9aa69c;border-radius:5px}.test-controls{display:flex;flex-wrap:wrap;align-items:center;gap:8px}.test-controls span{font-size:12px;color:#526456}.settings fieldset,.tool-group,.history-tools{display:flex;flex-wrap:wrap;gap:5px}.settings legend{font-weight:600}.board-area{overflow:auto}.accessible-toggle{margin:0 0 12px;padding:9px 12px;border:1px solid #9aa69c;border-radius:6px;background:#fff;cursor:pointer}.accessible-grid-wrap{max-width:100%;overflow:auto}.accessible-grid{border-collapse:collapse}.accessible-grid th{min-width:100px;padding:5px;border:1px solid #cbd2cc;text-align:center;font-size:12px}.accessible-grid td{padding:0;border:1px solid #bbc6bc}.accessible-grid td button{width:36px;height:36px;padding:2px;border:0;background:#fff;font-size:20px;cursor:pointer}.accessible-grid td button[aria-pressed="true"]{background:#dce5dd}.accessible-grid td button:focus{position:relative;z-index:1;outline:3px solid #2478c8}.clues-and-board{width:max-content;margin:auto}.column-clues{height:86px;display:flex;margin-left:80px}.clue-line{display:flex;flex-direction:column;justify-content:flex-end;align-items:center;min-width:calc(480px / 30);flex:1;padding-bottom:7px;font-size:12px;line-height:1.25}.row-and-board{display:flex;align-items:stretch}.row-clues{width:80px;display:flex;flex-direction:column;text-align:right;padding-right:9px}.row-clues .clue-line{justify-content:center;align-items:flex-end}.row-and-board canvas{display:block;width:min(480px,calc(100vw - 150px));height:auto;border:1px solid #bbc6bc;touch-action:none;cursor:crosshair}.row-and-board canvas:focus{outline:3px solid #2478c8;outline-offset:2px}.settings fieldset{border:1px solid #cbd2cc;border-radius:6px}@media(max-width:760px){.editor-layout{grid-template-columns:1fr}.settings{display:grid;grid-template-columns:1fr 1fr}.settings fieldset,.settings p{grid-column:1/-1}.editor-header{align-items:flex-start}.save-area{align-items:flex-end;flex-direction:column}}
.editor-page .settings button,.editor-page .save-area button,.editor-page .accessible-toggle{min-height:44px;min-width:44px}
.editor-page .settings input,.editor-page .settings textarea{min-height:44px;font:inherit}
.editor-page .accessible-grid td button{width:44px;height:44px}
.editor-page canvas{touch-action:none}
.editor-page button:focus-visible,.editor-page input:focus-visible,.editor-page textarea:focus-visible{outline:3px solid #2478c8;outline-offset:2px}
.palette-controls{display:grid!important;grid-template-columns:1fr;gap:8px!important}.palette-controls>p{margin:0;font-size:12px}.palette-entry{display:grid;grid-template-columns:44px minmax(70px,1fr) minmax(80px,1fr) auto;align-items:center;gap:6px}.palette-entry label{display:block}.palette-entry input{width:100%;min-width:0;padding:6px;border:1px solid #9aa69c;border-radius:5px}.palette-select{background:var(--palette-color)!important;color:#fff!important;text-shadow:0 1px 2px #000}.palette-controls>button,.mode-picker button{min-height:40px}.editor-page .clue-line span[title]{font-weight:700;text-shadow:0 0 1px #fff}.editor-page .save-area{flex-wrap:wrap}
@media(forced-colors:active){.editor-page button[aria-pressed="true"]{border:2px solid ButtonText}}
</style>

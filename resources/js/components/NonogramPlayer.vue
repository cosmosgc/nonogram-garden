<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { readJsonResponse } from '../lib/nonogram/http';
import { isColorCandidateComplete } from '../lib/nonogram/color-player';
import type { ColorClue, ColorPaletteEntry } from '../lib/nonogram/color-grid';

type Mark = number;
type Clue = number[];
type PlayerClue = Clue | ColorClue[];
type StartHint = { row_index: number; column_index: number; row_cells: string; column_cells: string };
const root = document.getElementById('nonogram-player');
const width = Number(root?.dataset.width);
const height = Number(root?.dataset.height);
const mode = root?.dataset.mode ?? 'classic';
const colorMode = mode === 'color';
const palette = JSON.parse(root?.dataset.palette ?? '[]') as ColorPaletteEntry[];
const rowClues = JSON.parse(root?.dataset.rowClues ?? '[]') as PlayerClue[];
const columnClues = JSON.parse(root?.dataset.columnClues ?? '[]') as PlayerClue[];
const puzzleId = Number(root?.dataset.puzzleId);
const revisionId = Number(root?.dataset.revisionId);
const progressUrl = root?.dataset.progressUrl;
const gameSessionUrl = root?.dataset.gameSessionUrl;
const gameModeUrl = root?.dataset.gameModeUrl;
const lifeCheckUrl = root?.dataset.lifeCheckUrl;
const gameRestartUrl = root?.dataset.gameRestartUrl;
const startHint = parseStartHint(root?.dataset.startHint);
const authenticated = Number(root?.dataset.userId) > 0;
const marks = ref<Mark[]>(Array(width * height).fill(0));
const tool = ref<'fill' | 'cross' | 'erase'>('fill');
const activeColorId = ref(1);
const cursorX = ref(0);
const cursorY = ref(0);
const pointerCursorIndex = ref<number | null>(null);
const gamepadCursors = ref<Record<number, { x: number; y: number }>>({});
const cellSize = ref(36);
const autoCross = ref(true);
const hardcoreMode = ref(false);
const livesRemaining = ref<number | null>(null);
const initialLives = ref<number | null>(null);
const gameStateReady = ref(!gameSessionUrl);
const gameModeLocked = ref(false);
const gameOver = ref(false);
const pendingGuessCells = ref(new Set<number>());
const checkingGuess = computed(() => pendingGuessCells.value.size > 0);
const updatingGameMode = ref(false);
const rowClueWidth = computed(() => Math.max(64, ...rowClues.map(clue => clueText(clue).length * 8 + 16)));
const columnClueHeight = computed(() => Math.max(44, ...columnClues.map(clue => clue.length * 20 + 8)));
const connectedGamepadCount = computed(() => Object.keys(gamepadCursors.value).length);
const paused = ref(false);
const elapsedSeconds = ref(0);
const clockPulse = ref(0);
const progressVersion = ref(0);
const mistakeCount = ref(0);
const progressReady = ref(false);
const progressStatus = ref('Loading progress…');
const progressConflict = ref(false);
const serverCompleted = ref(false);
const replayMode = ref(false);
const localConflictCopy = ref<{ marks: string; elapsedSeconds: number; serverVersion: number } | null>(null);
let activeSince: number | null = null;
let timer: ReturnType<typeof setInterval> | undefined;
let gamepadTimer: ReturnType<typeof setInterval> | undefined;
let saveTimer: ReturnType<typeof setTimeout> | undefined;
let saving = false;
let saveQueued = false;
let lastGuestSaveAt = 0;
let syncingGameState = false;
let autoCrossBeforeReplay = true;
let paintingPointerId: number | null = null;
let paintingValue: Mark = 1;
let suppressPointerClickAt: { index: number; time: number } | null = null;
const gamepadButtons = new Map<number, boolean[]>();
const gamepadDirections = new Map<number, { x: number; y: number; nextMoveAt: number }>();
const confirmedFilledCells = new Set<number>();
const pendingGuessIds = new Map<number, string>();

function parseStartHint(raw: string | undefined): StartHint | null {
    if (!raw || colorMode) return null;
    try {
        const hint = JSON.parse(raw) as Partial<StartHint> | null;
        if (!hint || !Number.isInteger(hint.row_index) || !Number.isInteger(hint.column_index)
            || hint.row_index! < 0 || hint.row_index! >= height
            || hint.column_index! < 0 || hint.column_index! >= width
            || typeof hint.row_cells !== 'string' || hint.row_cells.length !== width || !validHintCells(hint.row_cells)
            || typeof hint.column_cells !== 'string' || hint.column_cells.length !== height || !validHintCells(hint.column_cells)) return null;
        return hint as StartHint;
    } catch {
        return null;
    }
}

function validHintCells(cells: string): boolean {
    if (!colorMode) return /^[01]+$/.test(cells);
    return [...cells].every(cell => cell === '0' || (Number(cell) >= 1 && Number(cell) <= palette.length));
}

function configuredRoute(value: string | undefined): string {
    if (!value) throw new Error('Player route configuration is missing. Reload this page and try again.');
    return value;
}

const elapsed = computed(() => {
    clockPulse.value;
    const activeSeconds = activeSince === null ? 0 : Math.floor((Date.now() - activeSince) / 1000);
    const total = elapsedSeconds.value + activeSeconds;
    return `${Math.floor(total / 60).toString().padStart(2, '0')}:${(total % 60).toString().padStart(2, '0')}`;
});
const candidateComplete = computed(() => {
    if (colorMode) {
        return isColorCandidateComplete(width, height, marks.value,
            rowClues as ColorClue[][], columnClues as ColorClue[][], palette.length);
    }
    if (marks.value.some(mark => mark === 0)) return false;
    const matches = (actual: number[], expected: Clue) => actual.join(',') === expected.join(',');
    for (let y = 0; y < height; y++) {
        const runs: number[] = [];
        let run = 0;
        for (let x = 0; x < width; x++) {
            if (marks.value[y * width + x] === 1) run++;
            else if (run) { runs.push(run); run = 0; }
        }
        if (run) runs.push(run);
        if (!matches(runs.length ? runs : [0], rowClues[y] as Clue)) return false;
    }
    for (let x = 0; x < width; x++) {
        const runs: number[] = [];
        let run = 0;
        for (let y = 0; y < height; y++) {
            if (marks.value[y * width + x] === 1) run++;
            else if (run) { runs.push(run); run = 0; }
        }
        if (run) runs.push(run);
        if (!matches(runs.length ? runs : [0], columnClues[x] as Clue)) return false;
    }
    return true;
});

function lineHasPattern(line: Mark[], clue: Clue, forcedFillIndex = -1): boolean {
    if (clue.length === 1 && clue[0] === 0) {
        return forcedFillIndex < 0 && line.every(mark => mark !== 1);
    }

    const memo = new Map<string, boolean>();
    function place(clueIndex: number, earliestStart: number): boolean {
        const key = `${clueIndex}:${earliestStart}`;
        const cached = memo.get(key);
        if (cached !== undefined) return cached;
        if (clueIndex >= clue.length) {
            for (let index = earliestStart; index < line.length; index++) {
                if (line[index] === 1 || index === forcedFillIndex) { memo.set(key, false); return false; }
            }
            memo.set(key, true);
            return true;
        }

        const runsLeft = clue.length - clueIndex;
        const cellsLeft = clue.slice(clueIndex).reduce((sum, count) => sum + count, 0) + runsLeft - 1;
        const maxStart = line.length - cellsLeft;
        for (let start = earliestStart; start <= maxStart; start++) {
            let valid = true;
            for (let index = earliestStart; index < start; index++) {
                if (line[index] === 1 || index === forcedFillIndex) { valid = false; break; }
            }
            if (!valid) continue;

            const end = start + clue[clueIndex];
            for (let index = start; index < end; index++) {
                if (line[index] === 2) { valid = false; break; }
            }
            if (!valid) continue;

            const hasAnotherRun = clueIndex + 1 < clue.length;
            const separator = end;
            if (hasAnotherRun && (line[separator] === 1 || separator === forcedFillIndex)) continue;
            const nextStart = hasAnotherRun ? separator + 1 : end;
            if (place(clueIndex + 1, nextStart)) { memo.set(key, true); return true; }
        }
        memo.set(key, false);
        return false;
    }

    return place(0, 0);
}

function autoCrossCompletedLines(source: Mark[]): Mark[] {
    const next = [...source];
    if (!autoCross.value || colorMode) return next;

    function crossForcedEmptyCells(indices: number[], clue: Clue): boolean {
        const line = indices.map(index => next[index] === 1 && !confirmedFilledCells.has(index) ? 0 : next[index]);
        const expectedFilled = clue.reduce((total, count) => total + count, 0);
        if (line.filter((mark, position) => mark === 1 && confirmedFilledCells.has(indices[position])).length !== expectedFilled || !lineHasPattern(line, clue)) return false;
        let changed = false;
        for (let position = 0; position < indices.length; position++) {
            if (line[position] === 0 && !lineHasPattern(line, clue, position)) {
                next[indices[position]] = 2;
                changed = true;
            }
        }
        return changed;
    }

    for (let pass = 0; pass < width + height; pass++) {
        let changed = false;
        for (let y = 0; y < height; y++) {
        changed = crossForcedEmptyCells(Array.from({ length: width }, (_, x) => y * width + x), rowClues[y] as Clue) || changed;
        }
        for (let x = 0; x < width; x++) {
        changed = crossForcedEmptyCells(Array.from({ length: height }, (_, y) => y * width + x), columnClues[x] as Clue) || changed;
        }
        if (!changed) break;
    }

    return next;
}

function applyGameState(state: { hardcore: boolean; lives_remaining: number | null; initial_lives: number | null; mode_locked: boolean; game_over: boolean }) {
    syncingGameState = true;
    hardcoreMode.value = state.hardcore;
    livesRemaining.value = state.lives_remaining;
    initialLives.value = state.initial_lives;
    gameModeLocked.value = state.mode_locked;
    gameOver.value = state.game_over;
    if (state.hardcore) autoCross.value = false;
    gameStateReady.value = true;
    syncingGameState = false;
}

async function loadGameState() {
    if (!gameSessionUrl) return;
    try {
        const response = await fetch(configuredRoute(gameSessionUrl), { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`Game rules could not be loaded (${response.status})`);
        applyGameState(await readJsonResponse(response));
    } catch (error) {
        progressStatus.value = error instanceof Error ? `${error.message}. Refresh before playing.` : 'Game rules could not be loaded.';
    }
}

async function updateGameMode(enabled: boolean) {
    if (!gameModeUrl || !gameStateReady.value || gameModeLocked.value || gameOver.value) return;
    const previous = !enabled;
    updatingGameMode.value = true;
    if (enabled) autoCross.value = false;
    else autoCross.value = true;
    try {
        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch(configuredRoute(gameModeUrl), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ hardcore: enabled }),
        });
        const payload = await readJsonResponse(response);
        if (!response.ok) {
            if (response.status === 409 && typeof payload.hardcore === 'boolean') {
                applyGameState(payload);
                progressStatus.value = payload.message ?? 'The game mode is locked for this attempt.';
                return;
            }
            throw new Error(payload.message ?? 'Game mode could not be changed.');
        }
        applyGameState(payload);
    } catch (error) {
        syncingGameState = true;
        hardcoreMode.value = previous;
        syncingGameState = false;
        autoCross.value = !previous;
        progressStatus.value = error instanceof Error ? error.message : 'Game mode could not be changed.';
    } finally {
        updatingGameMode.value = false;
    }
}

watch(hardcoreMode, enabled => {
    if (!syncingGameState) void updateGameMode(enabled);
}, { flush: 'sync' });

function createGuessId(): string {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
        const random = Math.floor(Math.random() * 16);
        return (character === 'x' ? random : (random & 0x3) | 0x8).toString(16);
    });
}

async function checkFilledCell(index: number, mark: number): Promise<boolean> {
    if (!lifeCheckUrl || replayMode.value) return true;
    if (pendingGuessCells.value.has(index)) return false;
    pendingGuessCells.value.add(index);
    const guessId = pendingGuessIds.get(index) ?? createGuessId();
    pendingGuessIds.set(index, guessId);
    try {
        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch(configuredRoute(lifeCheckUrl), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ cell_index: index, guess_id: guessId, ...(colorMode ? { mark } : {}) }),
        });
        const previousLives = livesRemaining.value;
        const payload = await readJsonResponse(response);
        if (typeof payload.hardcore === 'boolean') applyGameState(payload);
        if (!payload.hardcore && payload.lives_remaining === previousLives) confirmedFilledCells.add(index);
        else confirmedFilledCells.delete(index);
        if (!response.ok) throw new Error(payload.message ?? 'The fill could not be checked.');
        pendingGuessIds.delete(index);
        if (gameOver.value) {
            if (activeSince !== null) elapsedSeconds.value += Math.floor((Date.now() - activeSince) / 1000);
            activeSince = null;
            paused.value = true;
        }
        return true;
    } catch (error) {
        progressStatus.value = error instanceof Error ? error.message : 'The fill could not be checked.';
        return false;
    } finally {
        pendingGuessCells.value.delete(index);
    }
}

function applyStartHintToProgress() {
    if (!startHint) return;
    const next = [...marks.value];
    for (let x = 0; x < width; x++) {
        const index = startHint.row_index * width + x;
        next[index] = colorMode ? (startHint.row_cells[x] === '0' ? 9 : Number(startHint.row_cells[x])) : (startHint.row_cells[x] === '1' ? 1 : 2);
        if (!colorMode && next[index] === 1) confirmedFilledCells.add(index);
        else confirmedFilledCells.delete(index);
    }
    for (let y = 0; y < height; y++) {
        const index = y * width + startHint.column_index;
        next[index] = colorMode ? (startHint.column_cells[y] === '0' ? 9 : Number(startHint.column_cells[y])) : (startHint.column_cells[y] === '1' ? 1 : 2);
        if (!colorMode && next[index] === 1) confirmedFilledCells.add(index);
        else confirmedFilledCells.delete(index);
    }
    if (next.join('') === marks.value.join('')) return;
    marks.value = next;
    progressStatus.value = `Start hint filled row ${startHint.row_index + 1} and column ${startHint.column_index + 1}.`;
    scheduleProgressSave();
}

function applyAutoCrossToLoadedProgress() {
    if (!autoCross.value || progressConflict.value || (serverCompleted.value && !replayMode.value)) return;
    const next = autoCrossCompletedLines(marks.value);
    if (next.join('') === marks.value.join('')) return;
    marks.value = next;
    scheduleProgressSave();
}

watch(autoCross, enabled => {
    if (!enabled || !progressReady.value || progressConflict.value || (serverCompleted.value && !replayMode.value)) return;
    const next = autoCrossCompletedLines(marks.value);
    if (next.join('') !== marks.value.join('')) {
        marks.value = next;
        scheduleProgressSave();
    }
});

function label(index: number): string {
    const mark = marks.value[index];
    const position = `Row ${Math.floor(index / width) + 1}, column ${index % width + 1}`;
    if (colorMode) {
        if (mark === 0) return `${position}: unknown`;
        if (mark === 9) return `${position}: crossed out`;
        return `${position}: ${palette[mark - 1]?.name ?? 'invalid colour'} (colour ${mark})`;
    }
    return `${position}: ${mark === 1 ? 'filled' : mark === 2 ? 'crossed out' : 'unknown'}`;
}

function clueText(clue: PlayerClue): string {
    if (!colorMode) {
        const clues = clue as Clue;

        return clues.length === 0 || (clues.length === 1 && clues[0] === 0) ? 'empty' : clues.join(' ');
    }
    return (clue as ColorClue[]).map(([length, colorId]) => `${length} ${palette[colorId - 1]?.name ?? `colour ${colorId}`}`).join(', ') || 'empty';
}

function clueItems(clue: PlayerClue): Array<{ text: string; colorId: number | null }> {
    if (!colorMode) return (clue as Clue).map(number => ({ text: String(number), colorId: null }));
    return (clue as ColorClue[]).map(([length, colorId]) => ({ text: String(length), colorId }));
}

function isValidMarks(value: string): boolean {
    if (value.length !== width * height) return false;
    return colorMode
        ? [...value].every(character => character === '0' || character === '9' || (Number(character) >= 1 && Number(character) <= palette.length))
        : /^[012]+$/.test(value);
}

function markGlyph(mark: number): string {
    if (mark === 0) return '';
    if (colorMode) return mark === 9 ? '×' : String(mark);
    return mark === 1 ? '■' : '×';
}

function cellStyle(mark: number): Record<string, string> {
    if (!colorMode || mark < 1 || mark > palette.length) return {};
    return { '--cell-color': palette[mark - 1].hex };
}

function paintingTool(mark: Mark): 'fill' | 'cross' | 'erase' {
    return mark === 0 ? 'erase' : mark === (colorMode ? 9 : 2) ? 'cross' : 'fill';
}

function totalElapsedSeconds(): number {
    return elapsedSeconds.value + (activeSince === null ? 0 : Math.floor((Date.now() - activeSince) / 1000));
}

function guestProgressKey(): string {
    return `nonogram:guest-progress:${puzzleId}:${revisionId}`;
}

function replayProgressKey(): string {
    const player = authenticated ? Number(root?.dataset.userId) : 'guest';
    return `nonogram:replay-progress:${player}:${puzzleId}:${revisionId}`;
}

function conflictProgressKey(): string {
    return `nonogram:conflict-progress:${Number(root?.dataset.userId)}:${puzzleId}:${revisionId}`;
}

function preserveConflictCopy(serverVersion: number) {
    const copy = { marks: marks.value.join(''), elapsedSeconds: totalElapsedSeconds(), serverVersion };
    localConflictCopy.value = copy;
    try {
        localStorage.setItem(conflictProgressKey(), JSON.stringify({ ...copy, puzzleId, revisionId }));
        progressStatus.value = 'Progress changed elsewhere. Your local copy is preserved in this browser.';
    } catch {
        progressStatus.value = 'Progress changed elsewhere. Keep this page open; local recovery storage is unavailable.';
    }
}

function useLocalConflictCopy() {
    const copy = localConflictCopy.value;
    if (!copy || !window.confirm('Restore your local progress over the newer account save?')) return;
    confirmedFilledCells.clear();
    marks.value = [...copy.marks].map(Number) as Mark[];
    elapsedSeconds.value = copy.elapsedSeconds;
    progressVersion.value = copy.serverVersion;
    progressConflict.value = false;
    localConflictCopy.value = null;
    try { localStorage.removeItem(conflictProgressKey()); } catch { /* Keep the in-memory copy. */ }
    progressStatus.value = 'Local progress restored; saving against the latest version.';
    scheduleProgressSave();
}

function discardLocalConflictCopy() {
    if (!window.confirm('Discard the local copy and reload the saved account progress?')) return;
    try { localStorage.removeItem(conflictProgressKey()); } catch { /* Reload still fetches the server copy. */ }
    window.location.reload();
}

function saveGuestProgress() {
    try {
        localStorage.setItem(guestProgressKey(), JSON.stringify({
            revisionId,
            marks: marks.value.join(''),
            elapsedSeconds: totalElapsedSeconds(),
            mistakeCount: 0,
        }));
        progressStatus.value = 'Progress saved on this device only';
    } catch {
        progressStatus.value = 'Local storage unavailable; progress will not persist';
    }
}

function saveReplayProgress() {
    try {
        localStorage.setItem(replayProgressKey(), JSON.stringify({ marks: marks.value.join(''), elapsedSeconds: totalElapsedSeconds() }));
        progressStatus.value = 'Replay progress saved on this device only; the original completion is preserved.';
    } catch {
        progressStatus.value = 'Replay is local only; replay progress could not be saved on this device.';
    }
}

function scheduleProgressSave() {
    if (!progressReady.value || progressConflict.value) return;
    if (serverCompleted.value) {
        if (replayMode.value) saveReplayProgress();
        return;
    }
    if (!authenticated) {
        saveGuestProgress();
        return;
    }
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(() => { void saveProgress(); }, 900);
}

async function loadProgress() {
    if (!authenticated) {
        let savedProgressFound = false;
        try {
            const raw = localStorage.getItem(guestProgressKey());
            if (raw) {
                const saved = JSON.parse(raw) as { revisionId?: unknown; marks?: unknown; elapsedSeconds?: unknown };
                if (saved.revisionId === revisionId && typeof saved.marks === 'string' && isValidMarks(saved.marks)) {
                    savedProgressFound = true;
                    marks.value = [...saved.marks].map(Number) as Mark[];
                    elapsedSeconds.value = Number.isInteger(saved.elapsedSeconds) && Number(saved.elapsedSeconds) >= 0 ? Number(saved.elapsedSeconds) : 0;
                    progressStatus.value = 'Guest progress restored from this device';
                }
            }
        } catch {
            progressStatus.value = 'Could not read local progress';
        }
        progressReady.value = true;
        if (!savedProgressFound) applyStartHintToProgress();
        applyAutoCrossToLoadedProgress();
        return;
    }

    try {
        const response = await fetch(configuredRoute(progressUrl), { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`Progress could not be loaded (${response.status})`);
        const payload = await readJsonResponse(response);
        const saved = payload.progress;
        if (payload.revision_id !== revisionId || typeof saved?.marks !== 'string' || !isValidMarks(saved.marks)) {
            throw new Error('The saved progress response was invalid.');
        }
        marks.value = [...saved.marks].map(Number) as Mark[];
        elapsedSeconds.value = Number.isInteger(saved.elapsed_seconds) && saved.elapsed_seconds >= 0 ? saved.elapsed_seconds : 0;
        progressVersion.value = Number.isInteger(saved.version) && saved.version >= 0 ? saved.version : 0;
        mistakeCount.value = Number.isInteger(saved.mistake_count) && saved.mistake_count >= 0 ? saved.mistake_count : 0;
        serverCompleted.value = typeof saved.completed_at === 'string' && saved.completed_at.length > 0;
        if (serverCompleted.value) progressStatus.value = 'This revision is already verified as complete.';
        else progressStatus.value = 'Progress loaded';
        try {
            const rawConflict = localStorage.getItem(conflictProgressKey());
            if (rawConflict) {
                const local = JSON.parse(rawConflict) as { puzzleId?: unknown; revisionId?: unknown; marks?: unknown; elapsedSeconds?: unknown; serverVersion?: unknown };
                if (local.puzzleId === puzzleId && local.revisionId === revisionId && typeof local.marks === 'string'
                    && isValidMarks(local.marks)
                    && Number.isInteger(local.elapsedSeconds) && Number(local.elapsedSeconds) >= 0
                    && Number.isInteger(local.serverVersion) && Number(local.serverVersion) >= 0) {
                    localConflictCopy.value = { marks: local.marks, elapsedSeconds: Number(local.elapsedSeconds), serverVersion: progressVersion.value };
                    progressConflict.value = true;
                    progressStatus.value = 'A local progress copy is available. Choose whether to restore it or reload the account save.';
                }
            }
        } catch { /* Ignore corrupt recovery metadata and keep the server copy. */ }
        progressReady.value = true;
        if (progressVersion.value === 0 && !progressConflict.value && !serverCompleted.value) applyStartHintToProgress();
        applyAutoCrossToLoadedProgress();
    } catch (error) {
        progressStatus.value = error instanceof Error ? `${error.message}. Refresh before playing.` : 'Progress could not be loaded. Refresh before playing.';
    }
}

async function saveProgress(): Promise<boolean> {
    if (!progressReady.value || progressConflict.value || serverCompleted.value) return false;
    if (!authenticated) {
        saveGuestProgress();
        return false;
    }
    if (saving) { saveQueued = true; progressStatus.value = 'Saving your latest progress…'; return false; }
    if (saveTimer) clearTimeout(saveTimer);
    const submittedMarks = marks.value.join('');
    const submittedElapsed = totalElapsedSeconds();
    const submittedVersion = progressVersion.value;
    saving = true;
    progressStatus.value = 'Saving progress…';
    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    try {
        const response = await fetch(configuredRoute(progressUrl), {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ marks: submittedMarks, elapsed_seconds: submittedElapsed, version: submittedVersion }),
        });
        const payload = await readJsonResponse(response);
        if (response.status === 409) {
            progressConflict.value = true;
            const currentVersion = Number.isInteger(payload.current_version) ? payload.current_version : submittedVersion;
            progressVersion.value = currentVersion;
            preserveConflictCopy(currentVersion);
            return false;
        }
        if (!response.ok) throw new Error(payload.message ?? `Progress save failed (${response.status})`);
        progressVersion.value = payload.progress.version;
        mistakeCount.value = payload.progress.mistake_count ?? mistakeCount.value;
        if (typeof payload.progress.completed_at === 'string' && payload.progress.completed_at.length > 0) {
            marks.value = [...payload.progress.marks].map(Number) as Mark[];
            elapsedSeconds.value = payload.progress.elapsed_seconds ?? submittedElapsed;
            serverCompleted.value = true;
            paused.value = true;
            activeSince = null;
            saveQueued = false;
            progressStatus.value = 'Solved — verified by the server';
        } else {
            progressStatus.value = 'Progress saved to your account';
        }
        if (marks.value.join('') !== submittedMarks || totalElapsedSeconds() > submittedElapsed) saveQueued = true;
        return true;
    } catch (error) {
        progressStatus.value = error instanceof Error ? `${error.message} — retrying after your next change` : 'Progress save failed';
        return false;
    } finally {
        saving = false;
        if (saveQueued && !progressConflict.value) {
            saveQueued = false;
            scheduleProgressSave();
        }
    }
}

function startReplay() {
    if (!serverCompleted.value || !progressReady.value) return;
    replayMode.value = true;
    autoCrossBeforeReplay = autoCross.value;
    autoCross.value = false;
    confirmedFilledCells.clear();
    gameOver.value = false;
    livesRemaining.value = null;
    marks.value = Array<Mark>(width * height).fill(0);
    elapsedSeconds.value = 0;
    mistakeCount.value = 0;
    let resumed = false;
    try {
        const raw = localStorage.getItem(replayProgressKey());
        if (raw) {
            const saved = JSON.parse(raw) as { marks?: unknown; elapsedSeconds?: unknown };
            if (typeof saved.marks === 'string' && isValidMarks(saved.marks)) {
                marks.value = [...saved.marks].map(Number) as Mark[];
                elapsedSeconds.value = Number.isInteger(saved.elapsedSeconds) && Number(saved.elapsedSeconds) >= 0 ? Number(saved.elapsedSeconds) : 0;
                resumed = true;
            }
        }
    } catch { /* Start a fresh local replay if stored replay data is corrupt. */ }
    if (!resumed) applyStartHintToProgress();
    const next = autoCrossCompletedLines(marks.value);
    if (next.join('') !== marks.value.join('')) marks.value = next;
    paused.value = false;
    activeSince = Date.now();
    progressStatus.value = resumed ? 'Resumed a local replay; the original completion is preserved.' : 'Started a local replay; the original completion is preserved.';
    clockPulse.value++;
    scheduleProgressSave();
}

async function restartAttempt() {
    if (!gameOver.value || !gameRestartUrl || serverCompleted.value) return;
    try {
        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch(configuredRoute(gameRestartUrl), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
        });
        const payload = await readJsonResponse(response);
        if (!response.ok) throw new Error(payload.message ?? 'A new attempt could not be started.');
        applyGameState(payload);
        confirmedFilledCells.clear();
        marks.value = Array<Mark>(width * height).fill(0);
        elapsedSeconds.value = 0;
        mistakeCount.value = 0;
        paused.value = false;
        activeSince = Date.now();
        applyStartHintToProgress();
        const next = autoCrossCompletedLines(marks.value);
        if (next.join('') !== marks.value.join('')) marks.value = next;
        progressStatus.value = 'New attempt started.';
        scheduleProgressSave();
    } catch (error) {
        progressStatus.value = error instanceof Error ? error.message : 'A new attempt could not be started.';
    }
}

function exitReplay() {
    if (!replayMode.value) return;
    if (activeSince !== null) elapsedSeconds.value += Math.floor((Date.now() - activeSince) / 1000);
    activeSince = null;
    paused.value = true;
    replayMode.value = false;
    autoCross.value = autoCrossBeforeReplay;
    clockPulse.value++;
    progressStatus.value = 'The original completion remains verified. Replay progress is saved locally.';
}

async function markCell(index: number, requestedTool: 'fill' | 'cross' | 'erase' = tool.value) {
    if (paused.value || !progressReady.value || !gameStateReady.value || pendingGuessCells.value.has(index) || gameOver.value || progressConflict.value || (serverCompleted.value && !replayMode.value)) return;
    const value: Mark = requestedTool === 'fill' ? (colorMode ? activeColorId.value : 1) : requestedTool === 'cross' ? (colorMode ? 9 : 2) : 0;
    if (marks.value[index] === value) return;
    const originalMark = marks.value[index];
    const isFilledColor = value > 0 && value !== (colorMode ? 9 : 2);
    if (isFilledColor && originalMark !== value) {
        if (!await checkFilledCell(index, value)) return;
        if (!colorMode && !lifeCheckUrl && !replayMode.value) confirmedFilledCells.add(index);
    } else if (value !== 1) confirmedFilledCells.delete(index);
    if (paused.value || gameOver.value || progressConflict.value || (serverCompleted.value && !replayMode.value)) return;
    const next = [...marks.value];
    next[index] = value;
    marks.value = autoCrossCompletedLines(next);
    scheduleProgressSave();
}

function onCellKey(index: number, event: KeyboardEvent) {
    if (['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp', 'w', 'a', 's', 'd', 'W', 'A', 'S', 'D'].includes(event.key)) {
        event.stopPropagation();
    const x = index % width;
    const y = Math.floor(index / width);
    const nextX = Math.max(0, Math.min(width - 1, x + (['ArrowRight', 'd', 'D'].includes(event.key) ? 1 : ['ArrowLeft', 'a', 'A'].includes(event.key) ? -1 : 0)));
    const nextY = Math.max(0, Math.min(height - 1, y + (['ArrowDown', 's', 'S'].includes(event.key) ? 1 : ['ArrowUp', 'w', 'W'].includes(event.key) ? -1 : 0)));
    if (nextX === x && nextY === y) return;
    event.preventDefault();
    cursorX.value = nextX;
    cursorY.value = nextY;
    nextTick(() => document.querySelector<HTMLButtonElement>(`[data-player-cell="${nextY * width + nextX}"]`)?.focus());
        return;
    }
    const action = event.key === ' ' || event.key === 'Enter' ? 'fill' : ['x', 'X'].includes(event.key) ? 'cross' : event.key === 'Backspace' ? 'erase' : null;
    if (action) {
        event.preventDefault();
        event.stopPropagation();
        void markCell(index, action);
    }
}

function onGlobalKeyboard(event: KeyboardEvent) {
    const target = event.target;
    if (target instanceof HTMLElement && (target.closest('input, textarea, select, [contenteditable="true"]')
        || (target.closest('button') && !target.closest('[data-player-cell]')))) return;

    const key = event.key;
    if (['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp', 'w', 'a', 's', 'd', 'W', 'A', 'S', 'D'].includes(key)) {
        const nextX = Math.max(0, Math.min(width - 1, cursorX.value + (['ArrowRight', 'd', 'D'].includes(key) ? 1 : ['ArrowLeft', 'a', 'A'].includes(key) ? -1 : 0)));
        const nextY = Math.max(0, Math.min(height - 1, cursorY.value + (['ArrowDown', 's', 'S'].includes(key) ? 1 : ['ArrowUp', 'w', 'W'].includes(key) ? -1 : 0)));
        event.preventDefault();
        cursorX.value = nextX;
        cursorY.value = nextY;
        nextTick(() => document.querySelector<HTMLButtonElement>(`[data-player-cell="${nextY * width + nextX}"]`)?.focus());
        return;
    }
    const action = key === ' ' || key === 'Enter' ? 'fill' : ['x', 'X'].includes(key) ? 'cross' : key === 'Backspace' ? 'erase' : null;
    if (!action) return;
    event.preventDefault();
    void markCell(cursorY.value * width + cursorX.value, action);
}

function onCellPointerDown(index: number, event: PointerEvent) {
    if (event.button !== 0 && event.button !== 2) return;
    event.preventDefault();
    pointerCursorIndex.value = index;
    suppressPointerClickAt = { index, time: Date.now() };
    paintingPointerId = event.pointerId;
    paintingValue = event.button === 2 || tool.value === 'cross' ? (colorMode ? 9 : 2) : tool.value === 'erase' ? 0 : (colorMode ? activeColorId.value : 1);
    void markCell(index, paintingTool(paintingValue));
}

function onCellClick(index: number) {
    if (suppressPointerClickAt?.index === index && Date.now() - suppressPointerClickAt.time < 500) {
        suppressPointerClickAt = null;
        return;
    }
    void markCell(index);
}

function onCellPointerEnter(index: number, event: PointerEvent) {
    pointerCursorIndex.value = index;
    if (paintingPointerId !== event.pointerId) return;
    void markCell(index, paintingTool(paintingValue));
}

function stopPainting(event: PointerEvent) {
    if (paintingPointerId === event.pointerId) paintingPointerId = null;
}

function gamepadCell(index: number): boolean {
    return Object.values(gamepadCursors.value).some(cursor => cursor.y * width + cursor.x === index);
}

function pollGamepads() {
    if (!navigator.getGamepads) return;
    const connectedPads = [...navigator.getGamepads()].filter((pad): pad is Gamepad => Boolean(pad?.connected));
    const activeIndices = new Set(connectedPads.map(pad => pad.index));
    for (const index of Object.keys(gamepadCursors.value).map(Number)) {
        if (!activeIndices.has(index)) {
            const { [index]: _removed, ...remaining } = gamepadCursors.value;
            gamepadCursors.value = remaining;
            gamepadButtons.delete(index);
            gamepadDirections.delete(index);
        }
    }
    for (const pad of connectedPads) {
        const cursor = gamepadCursors.value[pad.index] ?? { x: 0, y: 0 };
        const previousButtons = gamepadButtons.get(pad.index) ?? [];
        const isPressed = (index: number) => Boolean(pad.buttons[index]?.pressed);
        const justPressed = (index: number) => isPressed(index) && !previousButtons[index];
        const axisX = Math.abs(pad.axes[0] ?? 0) > 0.55 ? Math.sign(pad.axes[0]) : 0;
        const axisY = Math.abs(pad.axes[1] ?? 0) > 0.55 ? Math.sign(pad.axes[1]) : 0;
        const directionX = (isPressed(15) || axisX > 0 ? 1 : 0) - (isPressed(14) || axisX < 0 ? 1 : 0);
        const directionY = (isPressed(13) || axisY > 0 ? 1 : 0) - (isPressed(12) || axisY < 0 ? 1 : 0);
        const previousDirection = gamepadDirections.get(pad.index) ?? { x: 0, y: 0, nextMoveAt: 0 };
        const now = Date.now();
        const directionChanged = directionX !== previousDirection.x || directionY !== previousDirection.y;
        const shouldMove = (directionX !== 0 || directionY !== 0) && (directionChanged || now >= previousDirection.nextMoveAt);
        const nextCursor = {
            x: Math.max(0, Math.min(width - 1, cursor.x + (shouldMove ? directionX : 0))),
            y: Math.max(0, Math.min(height - 1, cursor.y + (shouldMove ? directionY : 0))),
        };
        gamepadDirections.set(pad.index, { x: directionX, y: directionY, nextMoveAt: shouldMove ? now + 260 : previousDirection.nextMoveAt });
        gamepadCursors.value = { ...gamepadCursors.value, [pad.index]: nextCursor };
        const cellIndex = nextCursor.y * width + nextCursor.x;
        if (justPressed(0)) void markCell(cellIndex, 'fill');
        if (justPressed(1)) void markCell(cellIndex, 'cross');
        if (justPressed(2)) void markCell(cellIndex, 'erase');
        gamepadButtons.set(pad.index, pad.buttons.map(button => button.pressed));
    }
}

function togglePause() {
    if (!progressReady.value || (serverCompleted.value && !replayMode.value)) return;
    if (paused.value) {
        paused.value = false;
        activeSince = Date.now();
    } else {
        if (activeSince !== null) elapsedSeconds.value += Math.floor((Date.now() - activeSince) / 1000);
        activeSince = null;
        paused.value = true;
    }
    clockPulse.value++;
    scheduleProgressSave();
}

onMounted(() => {
    window.addEventListener('keydown', onGlobalKeyboard);
    window.addEventListener('pointerup', stopPainting);
    window.addEventListener('pointercancel', stopPainting);
    gamepadTimer = setInterval(pollGamepads, 100);
    void (async () => {
        await loadGameState();
        await loadProgress();
        if (!progressReady.value) return;
        paused.value = serverCompleted.value;
        activeSince = serverCompleted.value ? null : Date.now();
        timer = setInterval(() => {
            clockPulse.value++;
            const elapsedNow = totalElapsedSeconds();
            if (authenticated && !paused.value && elapsedNow > 0 && elapsedNow % 15 === 0) scheduleProgressSave();
            else if (!authenticated && elapsedNow - lastGuestSaveAt >= 10) {
                lastGuestSaveAt = elapsedNow;
                if (replayMode.value) saveReplayProgress();
                else saveGuestProgress();
            }
        }, 1000);
    })();
});
onUnmounted(() => {
    if (timer) clearInterval(timer);
    if (gamepadTimer) clearInterval(gamepadTimer);
    window.removeEventListener('keydown', onGlobalKeyboard);
    window.removeEventListener('pointerup', stopPainting);
    window.removeEventListener('pointercancel', stopPainting);
    if (saveTimer) clearTimeout(saveTimer);
    if (!authenticated && progressReady.value) {
        if (replayMode.value) saveReplayProgress();
        else saveGuestProgress();
    }
    if (authenticated && replayMode.value && progressReady.value) saveReplayProgress();
});
</script>

<template>
  <main class="player-page">
    <div class="player-toolbar">
      <div class="player-tools" role="group" aria-label="Player mark">
        <button v-if="!colorMode" v-for="item in ['fill', 'cross', 'erase'] as const" :key="item" :disabled="!progressReady || !gameStateReady || gameOver || checkingGuess || updatingGameMode || progressConflict || (serverCompleted && !replayMode)" :aria-pressed="tool === item" @click="tool = item">{{ item === 'fill' ? 'Fill' : item === 'cross' ? 'Cross out' : 'Erase' }}</button>
        <template v-if="colorMode">
          <button v-for="(entry, index) in palette" :key="entry.hex" class="palette-mark" :disabled="!progressReady || !gameStateReady || gameOver || updatingGameMode || progressConflict || (serverCompleted && !replayMode)" :aria-label="`Fill with ${entry.name}, colour ${index + 1}`" :aria-pressed="tool === 'fill' && activeColorId === index + 1" @click="tool = 'fill'; activeColorId = index + 1"><span class="palette-swatch" :style="{ backgroundColor: entry.hex }" aria-hidden="true"></span><span>{{ index + 1 }} · {{ entry.name }}</span></button>
          <button :disabled="!progressReady || !gameStateReady || gameOver || progressConflict || (serverCompleted && !replayMode)" :aria-pressed="tool === 'cross'" @click="tool = 'cross'">Cross out ×</button>
          <button :disabled="!progressReady || !gameStateReady || gameOver || progressConflict || (serverCompleted && !replayMode)" :aria-pressed="tool === 'erase'" @click="tool = 'erase'">Erase</button>
        </template>
      </div>
      <div class="player-controls">
        <label v-if="gameSessionUrl" class="player-hardcore"><input v-model="hardcoreMode" type="checkbox" :disabled="!gameStateReady || gameModeLocked || gameOver || checkingGuess || updatingGameMode || serverCompleted"> Hardcore mode</label>
        <span v-if="gameSessionUrl" class="player-lives">{{ hardcoreMode || replayMode ? 'Lives: off' : `Lives: ${livesRemaining ?? '…'} / ${initialLives ?? '…'}` }}</span>
        <label v-if="!colorMode" class="player-auto-cross"><input v-model="autoCross" type="checkbox" :disabled="!progressReady || !gameStateReady || hardcoreMode || replayMode || gameOver || progressConflict || (serverCompleted && !replayMode)"> Auto-cross completed lines</label>
        <span role="timer" aria-label="Elapsed time">{{ elapsed }}</span>
        <span>Mistakes: {{ mistakeCount }}</span>
        <button :disabled="!progressReady || gameOver || (serverCompleted && !replayMode)" @click="togglePause">{{ serverCompleted && !replayMode ? 'Completed' : paused ? 'Resume' : 'Pause' }}</button>
        <button v-if="gameOver && !serverCompleted" @click="restartAttempt">Try again</button>
        <button v-if="serverCompleted && !replayMode" @click="startReplay">Play again</button>
        <button v-else-if="serverCompleted && replayMode" @click="exitReplay">Back to completed result</button>
        <button v-if="authenticated" :disabled="!progressReady || progressConflict || serverCompleted" @click="saveProgress">Save progress</button>
        <button aria-label="Zoom out" :disabled="cellSize <= 24" @click="cellSize = Math.max(24, cellSize - 4)">−</button>
        <button aria-label="Zoom in" :disabled="cellSize >= 52" @click="cellSize = Math.min(52, cellSize + 4)">+</button>
      </div>
    </div>
    <p class="player-help">Keyboard: arrows or WASD move, Enter/Space fills, X crosses, Backspace erases. Mouse: left-drag to fill, right-click to cross. Gamepads: D-pad or left stick moves; A fills, B crosses, X erases. Every connected controller gets its own cursor; all inputs share this board. <span v-if="connectedGamepadCount" role="status">{{ connectedGamepadCount }} gamepad{{ connectedGamepadCount === 1 ? '' : 's' }} connected.</span></p>
    <p v-if="startHint" class="player-start-hint">Starting hint filled row {{ startHint.row_index + 1 }} and column {{ startHint.column_index + 1 }}.</p>
    <p v-else-if="width === 1 || height === 1" class="player-start-hint">Starting line hints are disabled for single-row or single-column puzzles to avoid revealing the entire answer.</p>
    <div class="player-grid-scroll">
      <table class="player-grid" aria-label="Nonogram puzzle" :style="{ '--player-cell-size': `${cellSize}px`, '--player-row-clue-width': `${rowClueWidth}px`, '--player-clue-height': `${columnClueHeight}px` }">
        <colgroup>
          <col :style="{ width: `${rowClueWidth}px` }">
          <col v-for="x in width" :key="x" :style="{ width: `${cellSize}px` }">
        </colgroup>
        <thead><tr><th scope="col" class="clue-corner"><span class="sr-only">Row clues</span></th><th v-for="(clue, x) in columnClues" :key="x" scope="col" :class="{ 'active-line': cursorX === x }" :style="{ height: `${columnClueHeight}px` }"><span class="sr-only">Column {{ x + 1 }} clue: {{ clueText(clue) }}</span><span class="column-clue" aria-hidden="true"><span v-for="(item, index) in clueItems(clue)" :key="index" :class="{ 'color-clue-item': item.colorId !== null }" :style="item.colorId ? { '--clue-color': palette[item.colorId - 1]?.hex } : {}"><span v-if="item.colorId" class="clue-color-id">{{ item.colorId }}</span>{{ item.text }}</span></span></th></tr></thead>
        <tbody><tr v-for="(clue, y) in rowClues" :key="y">
          <th scope="row" :class="{ 'active-line': cursorY === y }"><span class="sr-only">Row {{ y + 1 }} clue: {{ clueText(clue) }}</span><span v-for="(item, index) in clueItems(clue)" :key="index" :class="{ 'color-clue-item': item.colorId !== null }" :style="item.colorId ? { '--clue-color': palette[item.colorId - 1]?.hex } : {}"><span v-if="item.colorId" class="clue-color-id">{{ item.colorId }}</span>{{ index > 0 ? ' ' : '' }}{{ item.text }}</span></th>
            <td v-for="x in width" :key="x" :class="{ 'active-line': cursorY === y || cursorX === x - 1 }">
              <button type="button" :data-player-cell="y * width + x - 1" :style="cellStyle(marks[y * width + x - 1])" :class="{ 'cell-filled': !colorMode && marks[y * width + x - 1] === 1, 'cell-crossed': !colorMode && marks[y * width + x - 1] === 2, 'cell-color-filled': colorMode && marks[y * width + x - 1] > 0 && marks[y * width + x - 1] !== 9, 'cell-color-crossed': colorMode && marks[y * width + x - 1] === 9, 'pointer-cursor': pointerCursorIndex === y * width + x - 1 }" :tabindex="cursorX === x - 1 && cursorY === y ? 0 : -1"
              :aria-label="label(y * width + x - 1)" :aria-pressed="marks[y * width + x - 1] !== 0" :disabled="paused || !progressReady || !gameStateReady || pendingGuessCells.has(y * width + x - 1) || updatingGameMode || gameOver || progressConflict || (serverCompleted && !replayMode)"
              @pointerdown="onCellPointerDown(y * width + x - 1, $event)" @pointerenter="onCellPointerEnter(y * width + x - 1, $event)" @click="onCellClick(y * width + x - 1)" @contextmenu.prevent @keydown="onCellKey(y * width + x - 1, $event)">
              <span aria-hidden="true">{{ markGlyph(marks[y * width + x - 1]) }}</span>
              <span v-for="(cursor, padIndex) in gamepadCursors" :key="padIndex" :hidden="cursor.y !== y || cursor.x !== x - 1" class="gamepad-cursor-badge" :aria-hidden="true">P{{ Number(padIndex) + 1 }}</span>
            </button>
          </td>
        </tr></tbody>
      </table>
    </div>
    <p v-if="gameOver" class="player-game-over" role="alert">No lives remain. Your progress is saved; start a new attempt to continue.</p>
    <p class="progress-status" role="status" aria-live="polite">{{ progressStatus }}</p>
    <div v-if="progressConflict && localConflictCopy" class="conflict-actions">
      <button @click="useLocalConflictCopy">Use my local progress</button>
      <button @click="discardLocalConflictCopy">Reload account save</button>
    </div>
    <div v-if="candidateComplete" class="candidate-status">
      <p role="status" aria-live="polite">{{ serverCompleted && !replayMode ? 'Puzzle completion verified by the server.' : replayMode ? 'Replay matches all clues locally; the original verified completion is preserved.' : authenticated ? 'All clues match. Saving progress automatically checks the exact solution on the server.' : 'All clues match locally. Sign in to save progress and have the server verify completion.' }}</p>
    </div>
    <p v-else class="candidate-status" role="status" aria-live="polite">{{ replayMode ? paused ? 'Replay paused' : 'Local replay in progress' : paused ? 'Paused' : 'In progress' }}</p>
  </main>
</template>

<style>
.player-page{max-width:1100px;margin:0 auto;padding:clamp(16px,4vw,32px);color:#17231f}.player-toolbar,.player-tools,.player-controls{display:flex;align-items:center;flex-wrap:wrap;gap:8px}.player-toolbar{justify-content:space-between;margin:18px 0;padding:16px;border:1px solid #dce4dc;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgb(24 46 34 / 5%)}.player-page button{min-width:44px;min-height:44px;padding:7px 12px;border:1px solid #d3ded4;border-radius:10px;background:white;color:#20352a;font-weight:650;cursor:pointer;transition:background .15s,border-color .15s}.player-page button:hover:not(:disabled){border-color:#236b50;background:#edf4ed}.player-tools button[aria-pressed="true"],.player-page button[aria-pressed="true"]{border-color:#236b50;background:#236b50;color:#fff}.player-page button:disabled{opacity:.5;cursor:not-allowed}.player-page button:focus-visible{outline:3px solid #e08b39;outline-offset:2px}.player-help{max-width:760px;color:#58685d;line-height:1.65}.player-start-hint{max-width:max-content;margin:12px 0;padding:10px 14px;border:1px solid #f0d6a7;border-radius:10px;background:#fff8e9;font-weight:650}.player-grid-scroll{max-width:100%;overflow:auto;border:1px solid #dce4dc;border-radius:14px;background:#fff}.player-grid{width:max-content;table-layout:fixed;border-collapse:collapse;margin:20px auto}.player-grid th{width:var(--player-cell-size);min-width:0;height:var(--player-clue-height);padding:0;border:1px solid #ccd7cd;background:#f5f8f4;text-align:center;vertical-align:bottom;white-space:nowrap;overflow:hidden}.player-grid .clue-corner{width:var(--player-row-clue-width);min-width:var(--player-row-clue-width)}.player-grid .column-clue{display:flex;height:var(--player-clue-height);flex-direction:column;align-items:center;justify-content:flex-end;font-size:13px;font-weight:700;line-height:20px}.player-grid tbody th{width:var(--player-row-clue-width);min-width:var(--player-row-clue-width);height:var(--player-cell-size);padding:0 8px 0 0;text-align:right;vertical-align:middle}.player-grid td{width:var(--player-cell-size);height:var(--player-cell-size);padding:0;border:1px solid #aebdaf}.player-grid td button{position:relative;display:grid;width:var(--player-cell-size);height:var(--player-cell-size);min-width:var(--player-cell-size);min-height:var(--player-cell-size);place-items:center;touch-action:none;padding:0;border:0;border-radius:0;background:#fff;font-size:18px}.player-grid td button.pointer-cursor{box-shadow:inset 0 0 0 2px #e08b39}.player-grid td button.cell-filled{background:#26352a;color:#fff}.player-grid td button.cell-crossed{background:#fff;color:#c24132}.player-grid td button.cell-crossed span:first-child{display:grid;width:72%;height:72%;place-items:center;border-radius:50%;background:#fff1ed;font-size:17px;font-weight:900;line-height:1}.gamepad-cursor-badge{position:absolute;right:1px;bottom:1px;display:grid;width:12px;height:12px;place-items:center;border-radius:50%;background:#e08b39;color:#fff;font-size:7px;font-weight:800;line-height:1}.player-auto-cross,.player-hardcore{display:flex;min-height:44px;align-items:center;gap:8px}.player-auto-cross input,.player-hardcore input{width:20px;height:20px;accent-color:#236b50}.player-lives{font-weight:700}.player-game-over{padding:12px;border:2px solid #a33;border-radius:10px;background:#fff4f4;color:#741d1d;font-weight:700}.player-grid .active-line{background-color:#e5f1e9}.player-grid td.active-line button:not(.cell-filled):not(.cell-crossed){background-color:#f2f8f2}.candidate-status{min-height:1.5em;font-weight:600}.conflict-actions{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}
@media(forced-colors:active){.player-page button[aria-pressed="true"]{border:2px solid ButtonText}}
.player-page .palette-mark{display:inline-flex;align-items:center;gap:7px}.player-page .palette-swatch{width:18px;height:18px;border:2px solid currentColor;border-radius:4px;box-shadow:inset 0 0 0 2px #fff}.player-grid .color-clue-item{display:inline-block;color:var(--clue-color);text-shadow:0 0 1px #17231f;font-weight:800}.player-grid .clue-color-id{display:inline-grid;width:14px;height:14px;margin-right:2px;place-items:center;border:1px solid currentColor;border-radius:50%;font-size:9px;line-height:1}.player-grid td button.cell-color-filled{background:var(--cell-color);color:#111;box-shadow:inset 0 0 0 2px #17231f;font-weight:800}.player-grid td button.cell-color-crossed{background:repeating-linear-gradient(135deg,#fff 0 4px,#d5ddd7 4px 7px);color:#17231f;font-weight:900}
</style>

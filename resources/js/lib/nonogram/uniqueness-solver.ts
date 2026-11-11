export type UniquenessStatus = 'unique' | 'ambiguous' | 'inconsistent' | 'unknown';
type Limits = { nodeLimit: number; timeLimitMilliseconds: number; candidateLimitPerLine: number; candidateLimit: number };
type Branch = { axis: 'row' | 'column'; index: number };
type Candidates = string[][];

const DEFAULT_LIMITS: Limits = {
    nodeLimit: 100_000,
    timeLimitMilliseconds: 1500,
    candidateLimitPerLine: 50_000,
    candidateLimit: 20_000,
};

/** Bounded browser-side check. Only the server may authorize publication. */
export function analyzeUniqueness(
    width: number,
    height: number,
    rowClues: number[][],
    columnClues: number[][],
    limits: Partial<Limits> = {},
): UniquenessStatus {
    if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1
        || width > 30 || height > 30 || rowClues.length !== height || columnClues.length !== width) return 'inconsistent';

    const budget = { ...DEFAULT_LIMITS, ...limits };
    let deadline = Date.now() + Math.max(0, budget.timeLimitMilliseconds);
    let candidatesGenerated = 0;
    let nodes = 0;
    let solutions = 0;
    let exhausted = false;

    const lineCandidates = (clues: number[], length: number): string[] => {
        if (clues.length === 1 && clues[0] === 0) {
            if (candidatesGenerated >= budget.candidateLimit || Date.now() >= deadline) {
                exhausted = true;
                return [];
            }
            candidatesGenerated++;
            return ['0'.repeat(length)];
        }
        if (clues.length === 0 || clues.some(run => !Number.isInteger(run) || run < 1)
            || clues.reduce((sum, run) => sum + run, 0) + clues.length - 1 > length) return [];

        const patterns: string[] = [];
        const placeRuns = (runIndex: number, cursor: number, cells: string[]) => {
            if (exhausted) return;
            if (patterns.length >= budget.candidateLimitPerLine
                || candidatesGenerated >= budget.candidateLimit || Date.now() >= deadline) {
                exhausted = true;
                return;
            }
            if (runIndex === clues.length) {
                patterns.push(cells.join(''));
                candidatesGenerated++;
                return;
            }
            const remainingRuns = clues.slice(runIndex);
            const minimumTail = remainingRuns.reduce((sum, run) => sum + run, 0) + remainingRuns.length - 1;
            const latestStart = length - minimumTail;
            for (let start = cursor; start <= latestStart; start++) {
                const next = [...cells];
                for (let cell = start; cell < start + clues[runIndex]; cell++) next[cell] = '1';
                placeRuns(runIndex + 1, start + clues[runIndex] + 1, next);
                if (exhausted) return;
            }
        };
        placeRuns(0, 0, Array<string>(length).fill('0'));
        return patterns;
    };

    const rows = rowClues.map(clues => lineCandidates(clues, width));
    const columns = columnClues.map(clues => lineCandidates(clues, height));
    if (exhausted) return 'unknown';
    if (rows.some(candidates => candidates.length === 0) || columns.some(candidates => candidates.length === 0)) return 'inconsistent';

    const propagate = (currentRows: Candidates, currentColumns: Candidates): boolean => {
        let changed: boolean;
        do {
            changed = false;
            for (let y = 0; y < currentRows.length; y++) {
                if (Date.now() >= deadline) { exhausted = true; return false; }
                for (let x = 0; x < currentColumns.length; x++) {
                    const allowed = new Set(currentRows[y].map(candidate => candidate[x]));
                    const filtered = currentColumns[x].filter(candidate => allowed.has(candidate[y]));
                    if (filtered.length === 0) return false;
                    if (filtered.length !== currentColumns[x].length) {
                        currentColumns[x] = filtered;
                        changed = true;
                    }
                }
            }
            for (let x = 0; x < currentColumns.length; x++) {
                if (Date.now() >= deadline) { exhausted = true; return false; }
                for (let y = 0; y < currentRows.length; y++) {
                    const allowed = new Set(currentColumns[x].map(candidate => candidate[y]));
                    const filtered = currentRows[y].filter(candidate => allowed.has(candidate[x]));
                    if (filtered.length === 0) return false;
                    if (filtered.length !== currentRows[y].length) {
                        currentRows[y] = filtered;
                        changed = true;
                    }
                }
            }
        } while (changed);
        return true;
    };

    const search = (currentRows: Candidates, currentColumns: Candidates): void => {
        if (solutions >= 2 || exhausted) return;
        if (++nodes > budget.nodeLimit || Date.now() >= deadline) { exhausted = true; return; }
        if (!propagate(currentRows, currentColumns)) return;

        let branch: Branch | null = null;
        let smallest = Number.MAX_SAFE_INTEGER;
        for (let index = 0; index < currentRows.length; index++) {
            if (currentRows[index].length > 1 && currentRows[index].length < smallest) {
                smallest = currentRows[index].length;
                branch = { axis: 'row', index };
            }
        }
        for (let index = 0; index < currentColumns.length; index++) {
            if (currentColumns[index].length > 1 && currentColumns[index].length < smallest) {
                smallest = currentColumns[index].length;
                branch = { axis: 'column', index };
            }
        }
        if (!branch) { solutions++; return; }

        const candidates = branch.axis === 'row' ? currentRows[branch.index] : currentColumns[branch.index];
        for (const candidate of candidates) {
            const nextRows = currentRows.map(line => [...line]);
            const nextColumns = currentColumns.map(line => [...line]);
            if (branch.axis === 'row') nextRows[branch.index] = [candidate];
            else nextColumns[branch.index] = [candidate];
            search(nextRows, nextColumns);
            if (solutions >= 2 || exhausted) return;
        }
    };

    search(rows, columns);
    if (solutions >= 2) return 'ambiguous';
    if (exhausted) return 'unknown';
    return solutions === 1 ? 'unique' : 'inconsistent';
}

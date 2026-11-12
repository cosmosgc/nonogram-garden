import { generateClues, validateGrid } from '../lib/nonogram/grid';
import { analyzeUniqueness } from '../lib/nonogram/uniqueness-solver';
import { generateColorClues } from '../lib/nonogram/color-grid';
import { analyzeColorUniqueness } from '../lib/nonogram/color-uniqueness-solver';
import type { DraftGridDocument } from '../lib/nonogram/draft-api';

type AnalysisMessage = { id: number; grid: DraftGridDocument };
type WorkerScope = {
    onmessage: ((event: MessageEvent<AnalysisMessage>) => void) | null;
    postMessage: (message: unknown) => void;
};

const workerScope = self as unknown as WorkerScope;
workerScope.onmessage = event => {
    const { id, grid } = event.data;
    try {
        if ('mode' in grid) {
            const clues = generateColorClues(grid);
            const status = analyzeColorUniqueness(grid.width, grid.height, clues.rows, clues.columns, grid.palette.length);
            workerScope.postMessage({ id, status });
            return;
        }
        validateGrid(grid);
        const clues = generateClues(grid);
        const status = analyzeUniqueness(grid.width, grid.height, clues.rows, clues.columns);
        workerScope.postMessage({ id, status });
    } catch (error) {
        workerScope.postMessage({ id, error: error instanceof Error ? error.message : 'Puzzle analysis failed.' });
    }
};

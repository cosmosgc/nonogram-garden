import { generateClues, validateGrid, type GridDocument } from '../lib/nonogram/grid';
import { analyzeUniqueness } from '../lib/nonogram/uniqueness-solver';

type AnalysisMessage = { id: number; grid: GridDocument };
type WorkerScope = {
    onmessage: ((event: MessageEvent<AnalysisMessage>) => void) | null;
    postMessage: (message: unknown) => void;
};

const workerScope = self as unknown as WorkerScope;
workerScope.onmessage = event => {
    const { id, grid } = event.data;
    try {
        validateGrid(grid);
        const clues = generateClues(grid);
        const status = analyzeUniqueness(grid.width, grid.height, clues.rows, clues.columns);
        workerScope.postMessage({ id, status });
    } catch (error) {
        workerScope.postMessage({ id, error: error instanceof Error ? error.message : 'Puzzle analysis failed.' });
    }
};

export type GridDocument = { version: 1; width: number; height: number; cells: string };
export type Clues = { rows: number[][]; columns: number[][] };

export function validateGrid(grid: GridDocument): void {
    if (grid.version !== 1 || !Number.isInteger(grid.width) || !Number.isInteger(grid.height)
        || grid.width < 1 || grid.height < 1 || grid.width > 30 || grid.height > 30
        || grid.width * grid.height > 900) {
        throw new Error('Grid dimensions or format version are invalid.');
    }
    if (grid.cells.length !== grid.width * grid.height || /[^01]/.test(grid.cells)) {
        throw new Error('Grid cells must be a row-major binary string matching width × height.');
    }
}

function lineClues(line: string): number[] {
    const runs = line.match(/1+/g)?.map(run => run.length) ?? [];
    return runs.length ? runs : [0];
}

export function pointerToCell(
    clientX: number,
    clientY: number,
    rect: { left: number; top: number; width: number; height: number },
    gridWidth: number,
    gridHeight: number,
): { x: number; y: number } | null {
    if (rect.width <= 0 || rect.height <= 0) return null;
    const x = Math.floor((clientX - rect.left) * gridWidth / rect.width);
    const y = Math.floor((clientY - rect.top) * gridHeight / rect.height);
    return x < 0 || y < 0 || x >= gridWidth || y >= gridHeight ? null : { x, y };
}

export function resizeGrid(grid: GridDocument, width: number, height: number): GridDocument {
    validateGrid(grid);
    if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1 || width > 30 || height > 30) {
        throw new Error('Grid dimensions must be integers from 1 to 30.');
    }
    const next = Array(width * height).fill('0');
    for (let y = 0; y < Math.min(grid.height, height); y++) {
        for (let x = 0; x < Math.min(grid.width, width); x++) {
            next[y * width + x] = grid.cells[y * grid.width + x];
        }
    }
    return { version: 1, width, height, cells: next.join('') };
}

export function generateClues(grid: GridDocument): Clues {
    validateGrid(grid);
    const rows = Array.from({ length: grid.height }, (_, y) =>
        lineClues(grid.cells.slice(y * grid.width, (y + 1) * grid.width)));
    const columns = Array.from({ length: grid.width }, (_, x) =>
        lineClues(Array.from({ length: grid.height }, (_, y) => grid.cells[y * grid.width + x]).join('')));
    return { rows, columns };
}

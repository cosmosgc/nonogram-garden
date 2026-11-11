export type ColorPaletteEntry = { name: string; hex: string };
export type ColorClue = [number, number];
export type ColorClues = { rows: ColorClue[][]; columns: ColorClue[][] };
export type ColorGridDocument = {
    mode: 'color';
    rules_version: 1;
    width: number;
    height: number;
    cells: string;
    palette: ColorPaletteEntry[];
};

export const MAX_COLORS = 8;

export function validateColorGrid(grid: ColorGridDocument): void {
    if (grid.rules_version !== 1 || !Number.isInteger(grid.width) || !Number.isInteger(grid.height)
        || grid.width < 1 || grid.height < 1 || grid.width > 30 || grid.height > 30
        || grid.width * grid.height > 900) {
        throw new Error('Grid dimensions or rules version are invalid.');
    }
    if (!Array.isArray(grid.palette) || grid.palette.length < 1 || grid.palette.length > MAX_COLORS) {
        throw new Error('A palette must contain between 1 and 8 entries.');
    }
    const hexValues = new Set<string>();
    for (const entry of grid.palette) {
        const name = entry.name.trim();
        if (name.length < 1 || name.length > 40) {
            throw new Error('Palette names must be 1 to 40 characters.');
        }
        if (!/^#[0-9A-F]{6}$/.test(entry.hex)) {
            throw new Error('Palette hex values must use uppercase #RRGGBB.');
        }
        if (hexValues.has(entry.hex)) {
            throw new Error('Palette hex values must be unique.');
        }
        hexValues.add(entry.hex);
    }
    if (grid.cells.length !== grid.width * grid.height) {
        throw new Error('Grid cells must be a row-major string matching width × height.');
    }
    const maxColor = grid.palette.length;
    for (const cell of grid.cells) {
        if (!/^[0-9]$/.test(cell) || Number(cell) > maxColor) {
            throw new Error('Every cell must be 0 or a palette colour ID.');
        }
    }
}

export function generateColorClues(grid: ColorGridDocument): ColorClues {
    validateColorGrid(grid);
    const rows = Array.from({ length: grid.height }, (_, y) =>
        lineColorClues(grid.cells.slice(y * grid.width, (y + 1) * grid.width)));
    const columns = Array.from({ length: grid.width }, (_, x) =>
        lineColorClues(Array.from({ length: grid.height }, (_, y) => grid.cells[y * grid.width + x]).join('')));
    return { rows, columns };
}

function lineColorClues(line: string): ColorClue[] {
    const runs: ColorClue[] = [];
    let index = 0;
    while (index < line.length) {
        const color = line[index];
        if (color === '0') {
            index++;
            continue;
        }

        let runLength = 1;
        while (index + runLength < line.length && line[index + runLength] === color) {
            runLength++;
        }
        runs.push([runLength, Number(color)]);
        index += runLength;
    }
    return runs;
}

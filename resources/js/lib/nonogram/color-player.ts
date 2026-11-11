import type { ColorClue } from './color-grid';

export type ColorPlayerMark = number;

export function isColorCandidateComplete(
    width: number,
    height: number,
    marks: ColorPlayerMark[],
    rowClues: ColorClue[][],
    columnClues: ColorClue[][],
    paletteSize: number,
): boolean {
    if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1
        || marks.length !== width * height || rowClues.length !== height || columnClues.length !== width
        || !Number.isInteger(paletteSize) || paletteSize < 1 || paletteSize > 8
        || marks.some(mark => !Number.isInteger(mark) || mark < 0 || (mark > paletteSize && mark !== 9))) {
        return false;
    }
    if (marks.includes(0)) return false;

    for (let y = 0; y < height; y++) {
        const line = marks.slice(y * width, (y + 1) * width);
        if (!matchesColorClue(line, rowClues[y])) return false;
    }
    for (let x = 0; x < width; x++) {
        const line = Array.from({ length: height }, (_, y) => marks[y * width + x]);
        if (!matchesColorClue(line, columnClues[x])) return false;
    }

    return true;
}

function matchesColorClue(line: ColorPlayerMark[], clue: ColorClue[]): boolean {
    const runs: ColorClue[] = [];
    for (let index = 0; index < line.length;) {
        const colorId = line[index];
        if (colorId === 0 || colorId === 9) {
            index++;
            continue;
        }

        let length = 1;
        while (index + length < line.length && line[index + length] === colorId) length++;
        runs.push([length, colorId]);
        index += length;
    }

    return runs.length === clue.length && runs.every(([length, colorId], index) =>
        length === clue[index][0] && colorId === clue[index][1]);
}

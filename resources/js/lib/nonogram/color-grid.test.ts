import { describe, expect, it } from 'vitest';
import colorRuleset from '../../../../tests/Fixtures/rulesets/color-v1.json';
import { generateColorClues, validateColorGrid, type ColorGridDocument } from './color-grid';

function document(overrides: Partial<ColorGridDocument> = {}): ColorGridDocument {
    return {
        mode: 'color',
        rules_version: 1,
        width: 1,
        height: 1,
        cells: '1',
        palette: colorRuleset.palette.map(entry => ({ ...entry })),
        ...overrides,
    };
}

describe('color grid clues', () => {
    for (const sharedCase of colorRuleset.cases) {
        it(`generates the shared clues for ${sharedCase.name}`, () => {
            expect(generateColorClues(document({
                width: sharedCase.width,
                height: sharedCase.height,
                cells: sharedCase.cells,
            }))).toEqual({ rows: sharedCase.rows, columns: sharedCase.columns });
        });
    }

    it('accepts a monochrome palette', () => {
        const grid = document({ palette: [{ name: 'Ink', hex: '#17231F' }] });

        expect(generateColorClues(grid)).toEqual({ rows: [[[1, 1]]], columns: [[[1, 1]]] });
    });

    it('rejects a cell colour missing from the palette', () => {
        expect(() => validateColorGrid(document({ width: 2, cells: '13' }))).toThrow();
    });

    it('rejects more than eight palette entries', () => {
        const palette = Array.from({ length: 9 }, (_, index) => ({
            name: `Colour ${index + 1}`,
            hex: `#${(index + 1).toString(16).repeat(3).toUpperCase()}`,
        }));

        expect(() => validateColorGrid(document({ palette }))).toThrow();
    });

    it('rejects lowercase hex, duplicate hex, and unknown rules versions', () => {
        expect(() => validateColorGrid(document({ palette: [{ name: 'Bad', hex: '#abcdef' }] }))).toThrow();
        expect(() => validateColorGrid(document({
            palette: [{ name: 'One', hex: '#C0392B' }, { name: 'Two', hex: '#C0392B' }],
        }))).toThrow();
        expect(() => validateColorGrid(document({ rules_version: 2 as 1 }))).toThrow();
    });

    it('rejects malformed cell strings and dimensions beyond the shared limits', () => {
        expect(() => validateColorGrid(document({ width: 2, cells: '1' }))).toThrow();
        expect(() => validateColorGrid(document({ width: 31, height: 1, cells: '0'.repeat(31) }))).toThrow();
    });
});

import { describe, expect, it } from 'vitest';
import classicRuleset from '../../../../tests/Fixtures/rulesets/classic-v1.json';
import { generateClues, pointerToCell, resizeGrid, validateGrid } from './grid';

describe('shared classic ruleset fixture', () => {
    for (const sharedCase of classicRuleset.cases) {
        it(`generates the shared clues for ${sharedCase.name}`, () => {
            expect(generateClues({
                version: 1,
                width: sharedCase.width,
                height: sharedCase.height,
                cells: sharedCase.cells,
            })).toEqual({ rows: sharedCase.rows, columns: sharedCase.columns });
        });
    }
});

describe('classic grid clues', () => {
    it('generates correct clues for a rectangular grid', () => {
        expect(generateClues({ version: 1, width: 4, height: 3, cells: '011010011000' })).toEqual({
            rows: [[2], [1, 1], [1]],
            columns: [[2], [1], [1], [1]],
        });
    });

    it('uses [0] for empty lines and emits full line runs', () => {
        expect(generateClues({ version: 1, width: 2, height: 2, cells: '0000' }).rows).toEqual([[0], [0]]);
        expect(generateClues({ version: 1, width: 2, height: 2, cells: '1111' }).columns).toEqual([[2], [2]]);
    });

    it('maps pointer coordinates to the correct cell at scaled canvas sizes', () => {
        const rect = { left: 100, top: 50, width: 960, height: 480 };
        expect(pointerToCell(580, 290, rect, 10, 10)).toEqual({ x: 5, y: 5 });
        expect(pointerToCell(99, 290, rect, 10, 10)).toBeNull();
        expect(pointerToCell(100, 50, { ...rect, width: 0 }, 10, 10)).toBeNull();
    });

    it('resizes while preserving the top-left overlap and padding with empty cells', () => {
        expect(resizeGrid({ version: 1, width: 2, height: 2, cells: '1001' }, 3, 2)).toEqual({
            version: 1, width: 3, height: 2, cells: '100010',
        });
        expect(resizeGrid({ version: 1, width: 3, height: 2, cells: '101011' }, 2, 1).cells).toBe('10');
    });

    it('rejects invalid dimensions, length, and cells', () => {
        expect(() => validateGrid({ version: 1, width: 31, height: 1, cells: '0'.repeat(31) })).toThrow();
        expect(() => validateGrid({ version: 1, width: 2, height: 2, cells: '010' })).toThrow();
        expect(() => validateGrid({ version: 1, width: 1, height: 1, cells: '2' })).toThrow();
    });
});

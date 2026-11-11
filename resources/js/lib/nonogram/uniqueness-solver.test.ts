import { describe, expect, it } from 'vitest';
import { analyzeUniqueness } from './uniqueness-solver';

describe('bounded client uniqueness solver', () => {
    it('distinguishes unique, ambiguous, and inconsistent clue sets', () => {
        expect(analyzeUniqueness(1, 1, [[1]], [[1]])).toBe('unique');
        expect(analyzeUniqueness(2, 2, [[1], [1]], [[1], [1]])).toBe('ambiguous');
        expect(analyzeUniqueness(2, 2, [[2], [2]], [[1], [1]])).toBe('inconsistent');
        expect(analyzeUniqueness(2, 2, [[3], [0]], [[1], [1]])).toBe('inconsistent');
    });

    it('matches brute-force classifications for every 2×2 clue combination', () => {
        const lineClues = (line: string) => line.match(/1+/g)?.map(run => run.length) ?? [0];
        const possibleLineClues = [[0], [1], [2]];
        const solutions = Array.from({ length: 16 }, (_, value) => value.toString(2).padStart(4, '0'));

        for (const firstRow of possibleLineClues) {
            for (const secondRow of possibleLineClues) {
                for (const firstColumn of possibleLineClues) {
                    for (const secondColumn of possibleLineClues) {
                        const rows = [firstRow, secondRow];
                        const columns = [firstColumn, secondColumn];
                        const matches = solutions.filter(cells =>
                            lineClues(cells.slice(0, 2)).join(',') === firstRow.join(',')
                            && lineClues(cells.slice(2, 4)).join(',') === secondRow.join(',')
                            && lineClues(cells[0] + cells[2]).join(',') === firstColumn.join(',')
                            && lineClues(cells[1] + cells[3]).join(',') === secondColumn.join(','));
                        const expected = matches.length === 0 ? 'inconsistent' : matches.length === 1 ? 'unique' : 'ambiguous';
                        expect(analyzeUniqueness(2, 2, rows, columns)).toBe(expected);
                    }
                }
            }
        }
    });

    it('returns unknown rather than asserting uniqueness when budgets are exhausted', () => {
        expect(analyzeUniqueness(1, 1, [[1]], [[1]], { nodeLimit: 0 })).toBe('unknown');
        expect(analyzeUniqueness(2, 2, [[1], [1]], [[1], [1]], { candidateLimit: 2 })).toBe('unknown');
        expect(analyzeUniqueness(1, 1, [[0]], [[0]], { candidateLimit: 1 })).toBe('unknown');
    });

    it('rejects invalid dimensions and clue-array shapes', () => {
        expect(analyzeUniqueness(31, 1, [[1]], Array(31).fill([1]))).toBe('inconsistent');
        expect(analyzeUniqueness(2, 2, [[1]], [[1], [1]])).toBe('inconsistent');
    });
});

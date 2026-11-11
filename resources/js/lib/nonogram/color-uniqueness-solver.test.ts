import { describe, expect, it } from 'vitest';
import colorRuleset from '../../../../tests/Fixtures/rulesets/color-v1.json';
import { analyzeColorUniqueness } from './color-uniqueness-solver';
import type { ColorClue } from './color-grid';

type SolverCase = {
    name: string;
    width: number;
    height: number;
    rows: ColorClue[][];
    columns: ColorClue[][];
    color_count: number;
    limits?: { nodeLimit?: number; timeLimitMilliseconds?: number };
    expected: 'unique' | 'ambiguous' | 'inconsistent' | 'unknown';
};

const solverCases = colorRuleset.solver_cases as SolverCase[];

describe('shared color solver fixture', () => {
    for (const sharedCase of solverCases) {
        it(`reports ${sharedCase.expected} for ${sharedCase.name}`, () => {
            expect(analyzeColorUniqueness(
                sharedCase.width,
                sharedCase.height,
                sharedCase.rows,
                sharedCase.columns,
                sharedCase.color_count,
                sharedCase.limits ?? {},
            )).toBe(sharedCase.expected);
        });
    }

    it('rejects a palette count beyond the supported range', () => {
        expect(analyzeColorUniqueness(1, 1, [[[1, 1]]], [[[1, 1]]], 0)).toBe('inconsistent');
        expect(analyzeColorUniqueness(1, 1, [[[1, 1]]], [[[1, 1]]], 9)).toBe('inconsistent');
    });
});

import { describe, expect, it } from 'vitest';
import { isColorCandidateComplete } from './color-player';

describe('Color Picross player candidate checks', () => {
    it('matches typed clues by exact colour IDs, including touching different colours', () => {
        expect(isColorCandidateComplete(2, 1, [1, 2], [[[1, 1], [1, 2]]], [[[1, 1]], [[1, 2]]], 2)).toBe(true);
        expect(isColorCandidateComplete(2, 1, [1, 1], [[[1, 1], [1, 2]]], [[[1, 1]], [[1, 2]]], 2)).toBe(false);
    });

    it('treats crossed marks as empty while requiring every cell to be marked', () => {
        expect(isColorCandidateComplete(2, 1, [1, 9], [[[1, 1]]], [[[1, 1]], []], 1)).toBe(true);
        expect(isColorCandidateComplete(2, 1, [1, 0], [[[1, 1]]], [[[1, 1]], []], 1)).toBe(false);
    });

    it('rejects marks outside the revision palette and invalid dimensions', () => {
        expect(isColorCandidateComplete(1, 1, [2], [[[1, 2]]], [[[1, 2]]], 1)).toBe(false);
        expect(isColorCandidateComplete(0, 1, [], [], [], 1)).toBe(false);
    });
});

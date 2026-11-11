import { describe, expect, it } from 'vitest';
import { parseDraftResponse } from './draft-api';

const valid = {
    id: 12,
    title: 'Test puzzle',
    description: 'A sample description',
    version: 4,
    updated_at: '2026-11-09T12:00:00Z',
    grid: { version: 1, width: 2, height: 2, cells: '0110' },
};

describe('draft response validation', () => {
    it('accepts and returns a valid draft payload', () => {
        expect(parseDraftResponse(valid)).toEqual(valid);
    });

    it('validates and returns optional normalized tags', () => {
        expect(parseDraftResponse({ ...valid, tags: ['logic', 'ocean-life'] }).tags).toEqual(['logic', 'ocean-life']);
        expect(() => parseDraftResponse({ ...valid, tags: ['Not valid'] })).toThrow();
    });

    it('rejects malformed identifiers, versions, and grid documents', () => {
        expect(() => parseDraftResponse({ ...valid, id: '12' })).toThrow();
        expect(() => parseDraftResponse({ ...valid, version: 0 })).toThrow();
        expect(() => parseDraftResponse({ ...valid, grid: { ...valid.grid, cells: '0' } })).toThrow();
    });
});

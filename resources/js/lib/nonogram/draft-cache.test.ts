import { describe, expect, it } from 'vitest';
import { draftCacheKey, encodeDraftCache, parseDraftCache } from './draft-cache';
import type { DraftCache } from './draft-cache';

const cache: DraftCache = {
    schemaVersion: 1,
    puzzleId: 42,
    serverVersion: 3,
    title: 'Recovered draft',
    description: 'Cached description',
    grid: { version: 1, width: 2, height: 2, cells: '0110' },
    savedAt: 1234,
};

describe('draft local recovery cache', () => {
    it('round trips a versioned cache record', () => {
        expect(parseDraftCache(encodeDraftCache(cache))).toEqual(cache);
    });

    it('round trips normalized tags while still accepting caches created before tag support', () => {
        const withTags = { ...cache, tags: ['logic', 'ocean-life'] };
        expect(parseDraftCache(encodeDraftCache(withTags))).toEqual(withTags);
        expect(parseDraftCache(encodeDraftCache(cache))).toEqual(cache);
    });

    it('ignores malformed or unsupported cache values', () => {
        expect(parseDraftCache('{')).toBeNull();
        expect(parseDraftCache(JSON.stringify({ ...cache, schemaVersion: 2 }))).toBeNull();
        expect(parseDraftCache(JSON.stringify({ ...cache, grid: { ...cache.grid, cells: 'x' } }))).toBeNull();
    });

    it('scopes cache keys to draft identity', () => {
        expect(draftCacheKey(null, 7)).toBe('nonogram:draft:7:new');
        expect(draftCacheKey(42, 7)).toBe('nonogram:draft:7:42');
    });
});

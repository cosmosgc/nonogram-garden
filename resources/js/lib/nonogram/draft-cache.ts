import { validateDraftGrid, type DraftGridDocument } from './draft-api';

export type DraftCache = {
    schemaVersion: 1;
    puzzleId: number | null;
    serverVersion: number;
    title: string;
    description: string;
    tags?: string[];
    grid: DraftGridDocument;
    savedAt: number;
};

export function parseDraftCache(value: string | null): DraftCache | null {
    if (!value) return null;
    try {
        const parsed: unknown = JSON.parse(value);
        if (!parsed || typeof parsed !== 'object') return null;
        const cache = parsed as Partial<DraftCache>;
        if (cache.schemaVersion !== 1 || !(cache.puzzleId === null || (Number.isInteger(cache.puzzleId) && cache.puzzleId! > 0))
            || !Number.isInteger(cache.serverVersion) || cache.serverVersion! < 1
            || typeof cache.title !== 'string' || typeof cache.description !== 'string'
            || (cache.tags !== undefined && (!Array.isArray(cache.tags) || cache.tags.length > 8
                || cache.tags.some(tag => typeof tag !== 'string' || tag.length > 24 || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(tag))))
            || !Number.isInteger(cache.savedAt)) return null;
        validateDraftGrid(cache.grid as DraftGridDocument);
        return cache as DraftCache;
    } catch {
        return null;
    }
}

export function encodeDraftCache(cache: DraftCache): string {
    validateDraftGrid(cache.grid);
    return JSON.stringify(cache);
}

export function draftCacheKey(puzzleId: number | null, userId: number): string {
    if (!Number.isInteger(userId) || userId < 1) throw new Error('A valid user id is required to scope local drafts.');
    return `nonogram:draft:${userId}:${puzzleId ?? 'new'}`;
}

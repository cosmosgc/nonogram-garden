import { validateGrid, type GridDocument } from './grid';
import { validateColorGrid, type ColorGridDocument } from './color-grid';

export type DraftGridDocument = GridDocument | ColorGridDocument;

export function validateDraftGrid(grid: DraftGridDocument): void {
    if ('mode' in grid) {
        if (grid.mode !== 'color') throw new Error('Draft mode is unsupported.');
        validateColorGrid(grid);
        return;
    }
    validateGrid(grid);
}

export type DraftResponse = {
    id: number;
    title: string;
    description: string | null;
    tags?: string[];
    version: number;
    updated_at: string | null;
    grid: DraftGridDocument;
};

export function parseDraftResponse(input: unknown): DraftResponse {
    if (!input || typeof input !== 'object') throw new Error('Draft response is malformed.');
    const value = input as Partial<DraftResponse>;
    if (!Number.isInteger(value.id) || value.id! < 1 || typeof value.title !== 'string'
        || !(value.description === null || typeof value.description === 'string')
        || !Number.isInteger(value.version) || value.version! < 1
        || !(value.updated_at === null || typeof value.updated_at === 'string')
        || (value.tags !== undefined && (!Array.isArray(value.tags) || value.tags.length > 8
            || value.tags.some(tag => typeof tag !== 'string' || tag.length > 24 || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(tag))))) {
        throw new Error('Draft response is missing valid identity or version fields.');
    }
    if (!value.grid || typeof value.grid !== 'object') throw new Error('Draft grid is malformed.');
    validateDraftGrid(value.grid as DraftGridDocument);
    return {
        id: value.id!,
        title: value.title,
        description: value.description,
        ...(value.tags === undefined ? {} : { tags: value.tags }),
        version: value.version!,
        updated_at: value.updated_at,
        grid: value.grid as GridDocument,
    };
}

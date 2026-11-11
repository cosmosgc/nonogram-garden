import { describe, expect, it } from 'vitest';
import { GridHistory, type GridSnapshot } from './history';

const blank: GridSnapshot = { width: 2, height: 1, cells: [0, 0] };
const filled: GridSnapshot = { width: 2, height: 1, cells: [1, 0] };

describe('GridHistory', () => {
    it('undoes and redoes a grid snapshot', () => {
        const history = new GridHistory();
        history.record(blank, filled);
        expect(history.canUndo).toBe(true);
        expect(history.undo(filled)).toEqual(blank);
        expect(history.redo(blank)).toEqual(filled);
    });

    it('records dimension changes and truncates redo after a new edit', () => {
        const history = new GridHistory();
        const resized: GridSnapshot = { width: 1, height: 2, cells: [0, 0] };
        history.record(blank, resized);
        expect(history.undo(resized)).toEqual(blank);
        history.record(blank, filled);
        expect(history.canRedo).toBe(false);
    });

    it('does not record unchanged snapshots and respects history limit', () => {
        const history = new GridHistory(1);
        history.record(blank, blank);
        expect(history.canUndo).toBe(false);
        history.record(blank, filled);
        history.record(filled, blank);
        expect(history.undo(blank)).toEqual(filled);
        expect(history.undo(filled)).toBeNull();
    });
});

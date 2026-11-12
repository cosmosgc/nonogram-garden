// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount as mountVue } from '@vue/test-utils';
import { nextTick } from 'vue';
import NonogramEditor from './NonogramEditor.vue';

const context = {
    clearRect: vi.fn(), fillRect: vi.fn(), beginPath: vi.fn(), moveTo: vi.fn(), lineTo: vi.fn(),
    stroke: vi.fn(), strokeRect: vi.fn(), setTransform: vi.fn(),
};

beforeEach(() => {
    document.body.innerHTML = '<div id="nonogram-editor" data-user-id="7" data-draft-store-url="/puzzles/drafts" data-draft-show-url-template="/puzzles/drafts/__PUZZLE_ID__" data-draft-update-url-template="/puzzles/drafts/__PUZZLE_ID__" data-publish-url-template="/puzzles/drafts/__PUZZLE_ID__/publish" data-puzzle-url-template="/puzzles/__PUZZLE_ID__" data-editor-new-url="/editor/new" data-home-url="/" data-import-url="/editor/import" data-editor-edit-url-template="/editor/__PUZZLE_ID__"></div>';
    localStorage.clear();
    vi.stubGlobal('fetch', vi.fn());
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context as unknown as CanvasRenderingContext2D);
    Object.defineProperty(HTMLCanvasElement.prototype, 'setPointerCapture', { value: vi.fn(), configurable: true });
    vi.spyOn(HTMLCanvasElement.prototype, 'getBoundingClientRect').mockReturnValue({
        left: 0, top: 0, width: 480, height: 480, right: 480, bottom: 480, x: 0, y: 0,
        toJSON: () => ({}),
    } as DOMRect);
});

const mount = (..._args: unknown[]) => mountVue(NonogramEditor, {
    attachTo: document.getElementById('nonogram-editor')!,
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    window.history.replaceState({}, '', '/');
});

describe('NonogramEditor', () => {
    it('supports keyboard grid navigation, painting, and undo/redo', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        const canvas = wrapper.get('canvas');
        expect(canvas.attributes('aria-label')).toContain('Row 1, column 1, empty');

        await canvas.trigger('keydown', { key: 'ArrowRight' });
        expect(canvas.attributes('aria-label')).toContain('Row 1, column 2, empty');
        await canvas.trigger('keydown', { key: 'Enter' });
        expect(canvas.attributes('aria-label')).toContain('Row 1, column 2, filled');

        const undo = wrapper.get('[aria-label="Edit history"]');
        expect(undo.text()).toContain('Undo');
        const undoButton = undo.findAll('button')[0];
        expect((undoButton.element as HTMLButtonElement).disabled).toBe(false);
        await undoButton.trigger('click');
        expect(canvas.attributes('aria-label')).toContain('Row 1, column 2, empty');
        const redoButton = undo.findAll('button')[1];
        expect((redoButton.element as HTMLButtonElement).disabled).toBe(false);
        await redoButton.trigger('click');
        expect(canvas.attributes('aria-label')).toContain('Row 1, column 2, filled');

        wrapper.unmount();
    });

    it('paints arrow-key movement while Space is held as one undoable stroke', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        const canvas = wrapper.get('canvas');
        const [undoButton] = wrapper.get('[aria-label="Edit history"]').findAll('button');

        await canvas.trigger('keydown', { key: ' ' });
        await canvas.trigger('keydown', { key: 'ArrowRight' });
        await canvas.trigger('keydown', { key: 'ArrowRight' });
        expect(canvas.attributes('aria-label')).toContain('Row 1, column 3, filled');
        expect((undoButton.element as HTMLButtonElement).disabled).toBe(true);

        window.dispatchEvent(new KeyboardEvent('keyup', { key: ' ' }));
        await nextTick();
        expect((undoButton.element as HTMLButtonElement).disabled).toBe(false);
        await undoButton.trigger('click');
        expect(canvas.attributes('aria-label')).toContain('Row 1, column 3, empty');
        expect(wrapper.get('.row-clues .clue-line').text()).toBe('0');

        wrapper.unmount();
    });

    it('restores an imported image grid into a new unsaved draft', async () => {
        const imported = { version: 1, width: 2, height: 1, cells: '10' };
        sessionStorage.setItem('nonogram:image-import:7', JSON.stringify(imported));
        window.history.replaceState({}, '', '/editor/new?image_import=1');
        expect(window.location.search).toBe('?image_import=1');
        expect(sessionStorage.getItem('nonogram:image-import:7')).toContain('"width":2');
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        await nextTick();

        expect(window.location.search).toBe('');
        expect(document.getElementById('nonogram-editor')?.dataset.userId).toBe('7');
        expect(wrapper.text()).toContain('Image grid imported');
        expect(sessionStorage.getItem('nonogram:image-import:7')).toBeNull();
        expect((wrapper.findAll('input[type="number"]')[0].element as HTMLInputElement).value).toBe('2');
        expect((wrapper.findAll('input[type="number"]')[1].element as HTMLInputElement).value).toBe('1');
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('1');
        expect(wrapper.get('input[maxlength="120"]').element).toBeTruthy();
        expect(sessionStorage.getItem('nonogram:image-import:7')).toBeNull();
        expect(fetch).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('offers a keyboard-operable semantic cell grid with labelled clues and states', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        const toggle = wrapper.get('.accessible-toggle');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        await toggle.trigger('click');

        const cells = wrapper.findAll('[data-accessible-cell]');
        expect(cells).toHaveLength(100);
        expect(wrapper.get('table[aria-label="Nonogram cell editor"]')).toBeTruthy();
        expect(cells[0].attributes('aria-label')).toContain('Row 1, column 1: empty');
        expect(wrapper.get('canvas').attributes('aria-hidden')).toBe('true');
        await cells[0].trigger('keydown', { key: 'ArrowRight' });
        expect((document.activeElement as HTMLElement).getAttribute('data-accessible-cell')).toBe('1');
        await wrapper.get('[data-accessible-cell="1"]').trigger('keydown', { key: 'Enter' });
        expect(wrapper.get('[data-accessible-cell="1"]').attributes('aria-label')).toContain('filled');
        wrapper.unmount();
    });

    it('paints a pointer drag as one undoable stroke', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        const canvas = wrapper.get('canvas');
        const dispatchPointer = async (type: string, x: number, y: number) => {
            const event = new Event(type, { bubbles: true });
            Object.defineProperties(event, {
                pointerId: { value: 3 }, clientX: { value: x }, clientY: { value: y },
            });
            canvas.element.dispatchEvent(event);
            await nextTick();
        };
        await dispatchPointer('pointerdown', 24, 24);
        await dispatchPointer('pointermove', 72, 24);
        await dispatchPointer('pointerup', 72, 24);
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('2');
        await wrapper.get('[aria-label="Edit history"]').findAll('button')[0].trigger('click');
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('0');
        wrapper.unmount();
    });

    it('ends a pointer stroke cleanly on cancellation and records one undo step', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        const canvas = wrapper.get('canvas');
        const dispatchPointer = async (type: string, x: number) => {
            const event = new Event(type, { bubbles: true });
            Object.defineProperties(event, { pointerId: { value: 8 }, clientX: { value: x }, clientY: { value: 24 } });
            canvas.element.dispatchEvent(event);
            await nextTick();
        };
        await dispatchPointer('pointerdown', 24);
        await dispatchPointer('pointermove', 72);
        await dispatchPointer('pointercancel', 72);
        await dispatchPointer('pointermove', 120);
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('2');
        await wrapper.get('[aria-label="Edit history"]').findAll('button')[0].trigger('click');
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('0');
        wrapper.unmount();
    });

    it('stops painting after leaving the canvas boundary', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        const canvas = wrapper.get('canvas');
        const dispatchPointer = async (type: string, x: number) => {
            const event = new Event(type, { bubbles: true });
            Object.defineProperties(event, { pointerId: { value: 9 }, clientX: { value: x }, clientY: { value: 24 } });
            canvas.element.dispatchEvent(event);
            await nextTick();
        };
        await dispatchPointer('pointerdown', 24);
        await dispatchPointer('pointerleave', 72);
        await dispatchPointer('pointermove', 120);
        await dispatchPointer('pointerup', 120);
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('1');
        wrapper.unmount();
    });

    it('erases and clears cells with undoable editor history', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        const dimensions = wrapper.findAll('input[type="number"]');
        await dimensions[0].setValue(1);
        await dimensions[1].setValue(1);
        const canvas = wrapper.get('canvas');
        await canvas.trigger('keydown', { key: 'Enter' });
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('1');

        await wrapper.get('.tool-group button[aria-pressed="false"]').trigger('click');
        await canvas.trigger('keydown', { key: 'Enter' });
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('0');
        await wrapper.get('.settings > button').trigger('click');
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('0');
        await wrapper.get('[aria-label="Edit history"]').findAll('button')[0].trigger('click');
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('1');
        wrapper.unmount();
    });

    it('loads an owned draft without immediately autosaving it again', async () => {
        vi.useFakeTimers();
        const responsePayload = {
            id: 5, title: 'Loaded', description: null, tags: ['logic'], version: 4,
            updated_at: '2026-11-09T12:00:00Z', grid: { version: 1, width: 2, height: 2, cells: '1001' },
        };
        const fetchMock = vi.mocked(fetch).mockResolvedValue({
            ok: true, status: 200, json: async () => responsePayload,
        } as Response);
        document.getElementById('nonogram-editor')!.dataset.puzzleId = '5';
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        await flushPromises();
        await nextTick();
        await vi.advanceTimersByTimeAsync(2000);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/puzzles/drafts/5');
        expect((wrapper.get('input[maxlength="120"]').element as HTMLInputElement).value).toBe('Loaded');
        expect(wrapper.get('[role="status"]').text()).toContain('Saved');
        wrapper.unmount();
    });

    it('autosaves debounced edits and displays the saved timestamp', async () => {
        vi.useFakeTimers();
        const responsePayload = {
            id: 42, title: 'Autosaved', description: 'notes', version: 1,
            updated_at: '2026-11-09T12:00:00Z', grid: { version: 1, width: 10, height: 10, cells: '0'.repeat(100) },
        };
        const fetchMock = vi.mocked(fetch).mockResolvedValue({
            ok: true, status: 201, json: async () => responsePayload,
        } as Response);
        const editorRoot = document.getElementById('nonogram-editor')!;
        editorRoot.dataset.draftStoreUrl = '/Nonogram%20laravel/public/puzzles/drafts';
        editorRoot.dataset.editorEditUrlTemplate = '/Nonogram%20laravel/public/editor/__PUZZLE_ID__';
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        await wrapper.get('input[maxlength="120"]').setValue('Autosaved');
        await wrapper.get('textarea').setValue('notes');
        await wrapper.get('input[maxlength="240"]').setValue('Logic, ocean-life, logic');
        await nextTick();
        expect(fetchMock).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/Nonogram%20laravel/public/puzzles/drafts');
        expect(window.location.pathname).toBe('/Nonogram%20laravel/public/editor/42');
        expect(JSON.parse(String(fetchMock.mock.calls[0][1]?.body)).tags).toEqual(['logic', 'ocean-life']);
        expect(wrapper.text()).toContain('Last saved');
        expect(wrapper.text()).toContain('Saved');
        expect(localStorage.getItem('nonogram:draft:7:new')).toBeNull();
        wrapper.unmount();
    });

    it('queues edits made while an autosave request is in flight', async () => {
        vi.useFakeTimers();
        let resolveFirst!: (response: Response) => void;
        const firstRequest = new Promise<Response>(resolve => { resolveFirst = resolve; });
        const firstResponse = {
            id: 71, title: 'First', description: '', version: 1,
            updated_at: '2026-11-09T12:00:00Z', grid: { version: 1, width: 10, height: 10, cells: '0'.repeat(100) },
        };
        const secondResponse = { ...firstResponse, title: 'Second', version: 2 };
        const fetchMock = vi.mocked(fetch)
            .mockReturnValueOnce(firstRequest)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => secondResponse } as Response);
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        const titleInput = wrapper.get('input[maxlength="120"]');
        await titleInput.setValue('First');
        await nextTick();
        await vi.advanceTimersByTimeAsync(800);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        await titleInput.setValue('Second');
        await nextTick();
        await vi.advanceTimersByTimeAsync(800);
        resolveFirst({ ok: true, status: 201, json: async () => firstResponse } as Response);
        await flushPromises();
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][1]?.method).toBe('PUT');
        expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body))).toMatchObject({ title: 'Second', version: 1 });
        expect(wrapper.text()).toContain('Saved');
        wrapper.unmount();
    });

    it('keeps edits locally on network failure and retries on the next change', async () => {
        vi.useFakeTimers();
        const responsePayload = {
            id: 51, title: 'Retried', description: '', version: 1,
            updated_at: '2026-11-09T12:00:00Z', grid: { version: 1, width: 10, height: 10, cells: '0'.repeat(100) },
        };
        vi.mocked(fetch)
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce({ ok: true, status: 201, json: async () => responsePayload } as Response);
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        const titleInput = wrapper.get('input[maxlength="120"]');
        await titleInput.setValue('First edit');
        await nextTick();
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();
        expect(wrapper.text()).toContain('edits kept locally');
        expect(localStorage.getItem('nonogram:draft:7:new')).toContain('First edit');

        await titleInput.setValue('Retried');
        await nextTick();
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();
        expect(wrapper.text()).toContain('Saved');
        expect(localStorage.getItem('nonogram:draft:7:new')).toBeNull();
        wrapper.unmount();
    });

    it('preserves local edits and blocks autosave after a stale-version conflict', async () => {
        vi.useFakeTimers();
        const serverPayload = {
            id: 42, title: 'Server title', description: null, version: 3,
            updated_at: '2026-11-09T12:00:00Z', grid: { version: 1, width: 10, height: 10, cells: '0'.repeat(100) },
        };
        document.body.innerHTML = '<div id="nonogram-editor" data-puzzle-id="42" data-user-id="7" data-draft-store-url="/puzzles/drafts" data-draft-show-url-template="/puzzles/drafts/__PUZZLE_ID__" data-draft-update-url-template="/puzzles/drafts/__PUZZLE_ID__" data-publish-url-template="/puzzles/drafts/__PUZZLE_ID__/publish" data-puzzle-url-template="/puzzles/__PUZZLE_ID__" data-editor-new-url="/editor/new" data-home-url="/" data-import-url="/editor/import" data-editor-edit-url-template="/editor/__PUZZLE_ID__"></div>';
        vi.mocked(fetch)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => serverPayload } as Response)
            .mockResolvedValueOnce({ ok: false, status: 409, json: async () => ({ message: 'Draft changed' }) } as Response);
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        await flushPromises();
        await wrapper.get('input[maxlength="120"]').setValue('Local copy');
        await nextTick();
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        expect(wrapper.text()).toContain('Conflict: recover local edits');
        expect(wrapper.text()).toContain('Restore local copy');
        expect(localStorage.getItem('nonogram:draft:7:42')).toContain('Local copy');
        wrapper.unmount();
    });

    it('supports author-only test play without changing the saved solution', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        const dimensions = wrapper.findAll('input[type="number"]');
        await dimensions[0].setValue(1);
        await dimensions[1].setValue(1);
        const canvas = wrapper.get('canvas');
        await canvas.trigger('keydown', { key: 'Enter' });
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('1');

        await wrapper.get('.test-controls button').trigger('click');
        expect(canvas.attributes('aria-label')).toContain('Test play, solution hidden');
        await canvas.trigger('keydown', { key: 'Enter' });
        expect(wrapper.get('.test-controls').text()).toContain('Correct solution!');
        await wrapper.get('.test-controls button').trigger('click');
        expect(canvas.attributes('aria-label')).not.toContain('Test play');
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('1');
        wrapper.unmount();
    });

    it('provides a semantic keyboard-operable grid alternative', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        await wrapper.get('.accessible-toggle').trigger('click');
        const firstCell = wrapper.get('[data-accessible-cell="0"]');
        expect(firstCell.attributes('aria-label')).toBe('Row 1, column 1: empty');
        expect(wrapper.get('canvas').attributes('aria-hidden')).toBe('true');

        await firstCell.trigger('click');
        expect(wrapper.find('.row-clues .clue-line').text()).toBe('1');
        expect(wrapper.get('[data-accessible-cell="0"]').attributes('aria-label')).toBe('Row 1, column 1: filled');
        await wrapper.get('[data-accessible-cell="0"]').trigger('keydown', { key: 'ArrowRight' });
        expect((document.activeElement as HTMLElement).getAttribute('data-accessible-cell')).toBe('1');
        await wrapper.get('[aria-label="Edit history"]').findAll('button')[0].trigger('click');
        expect(wrapper.get('[data-accessible-cell="0"]').attributes('aria-label')).toBe('Row 1, column 1: empty');
        wrapper.unmount();
    });

    it('supports held-Space arrow strokes in the semantic cell grid', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        await wrapper.get('.accessible-toggle').trigger('click');
        const firstCell = wrapper.get('[data-accessible-cell="0"]');

        await firstCell.trigger('keydown', { key: ' ' });
        await firstCell.trigger('keydown', { key: 'ArrowRight' });
        window.dispatchEvent(new KeyboardEvent('keyup', { key: ' ' }));
        await nextTick();

        expect(wrapper.get('[data-accessible-cell="0"]').attributes('aria-label')).toContain('filled');
        expect(wrapper.get('[data-accessible-cell="1"]').attributes('aria-label')).toContain('filled');
        const [undoButton] = wrapper.get('[aria-label="Edit history"]').findAll('button');
        await undoButton.trigger('click');
        expect(wrapper.get('[data-accessible-cell="0"]').attributes('aria-label')).toContain('empty');
        expect(wrapper.get('[data-accessible-cell="1"]').attributes('aria-label')).toContain('empty');

        wrapper.unmount();
    });

    it('announces empty row and column clues in the semantic grid', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        await wrapper.get('.accessible-toggle').trigger('click');

        expect(wrapper.get('.accessible-grid thead th:nth-child(2)').text()).toBe('Column 1 clue: empty');
        expect(wrapper.get('.accessible-grid tbody th').text()).toBe('Row 1 clue: empty');
        wrapper.unmount();
    });

    it('does not expose the answer in the accessible grid during test play', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        const dimensions = wrapper.findAll('input[type="number"]');
        await dimensions[0].setValue(1);
        await dimensions[1].setValue(1);
        await wrapper.get('canvas').trigger('keydown', { key: 'Enter' });
        await wrapper.get('.test-controls button').trigger('click');
        await wrapper.get('.accessible-toggle').trigger('click');

        expect(wrapper.get('[data-accessible-cell="0"]').attributes('aria-label')).toBe('Row 1, column 1: empty');
        await wrapper.get('[data-accessible-cell="0"]').trigger('click');
        expect(wrapper.get('.test-controls').text()).toContain('Correct solution!');
        wrapper.unmount();
    });

    it('exposes zoom controls and the explicit fill/erase tools', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        const canvas = wrapper.get('canvas');
        expect(canvas.attributes('style')).toContain('480px');
        await wrapper.get('button[aria-label="Zoom in"]').trigger('click');
        expect(canvas.attributes('style')).toContain('528px');
        await wrapper.get('.tool-group button[aria-pressed="false"]').trigger('click');
        expect(wrapper.get('.tool-group button[aria-pressed="true"]').text()).toBe('Erase');
        wrapper.unmount();
    });

    it('paints selected Color Picross colors and previews color-aware clues', async () => {
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        await wrapper.get('.mode-picker button:nth-of-type(2)').trigger('click');
        expect((wrapper.get('input[aria-label="Color 1 name"]').element as HTMLInputElement).value).toBe('Crimson');
        await wrapper.get('button[aria-label="Select Ocean for painting"]').trigger('click');
        await wrapper.get('canvas').trigger('keydown', { key: 'Enter' });

        expect(wrapper.find('.row-clues .clue-line').text()).toContain('1◆');
        expect(wrapper.get('canvas').attributes('aria-label')).toContain('filled Ocean');
        wrapper.unmount();
    });

    it('autosaves the Color Picross document and palette together', async () => {
        vi.useFakeTimers();
        const colorDocument = {
            mode: 'color', rules_version: 1, width: 10, height: 10,
            cells: '0'.repeat(100), palette: [
                { name: 'Crimson', hex: '#C0392B' },
                { name: 'Ocean', hex: '#2471A3' },
            ],
        };
        const fetchMock = vi.mocked(fetch).mockResolvedValue({
            ok: true, status: 201, json: async () => ({
                id: 61, title: 'Untitled puzzle', description: '', version: 1,
                updated_at: '2026-11-09T12:00:00Z', grid: colorDocument,
            }),
        } as Response);
        const wrapper = mount(NonogramEditor, { attachTo: document.body });
        await wrapper.get('.mode-picker button:nth-of-type(2)').trigger('click');
        await wrapper.get('button[aria-label="Select Ocean for painting"]').trigger('click');
        await wrapper.get('canvas').trigger('keydown', { key: 'Enter' });
        await vi.advanceTimersByTimeAsync(800);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const submitted = JSON.parse(String(fetchMock.mock.calls[0][1]?.body));
        expect(submitted.grid).toMatchObject({ mode: 'color', rules_version: 1, palette: colorDocument.palette });
        expect(submitted.grid.cells[0]).toBe('2');
        wrapper.unmount();
    });
});

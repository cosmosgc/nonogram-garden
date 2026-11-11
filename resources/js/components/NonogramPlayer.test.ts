// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import NonogramPlayer from './NonogramPlayer.vue';

function mountPlayer(width = 2, height = 2, rows = [[1], [1]], columns = [[1], [1]], userId?: number, basePath = '', hint: { row_index: number; column_index: number; row_cells: string; column_cells: string } | null = null, lifeApi = false) {
    const gameAttrs = lifeApi ? `data-game-session-url="${basePath}/game-session" data-game-mode-url="${basePath}/game-mode" data-life-check-url="${basePath}/life-check" data-game-restart-url="${basePath}/restart"` : '';
    document.body.innerHTML = `<div id="nonogram-player" data-width="${width}" data-height="${height}" data-puzzle-id="12" data-revision-id="34" data-progress-url="${basePath}/puzzles/12/revisions/34/progress" data-completion-url="${basePath}/puzzles/12/revisions/34/complete" ${gameAttrs} data-start-hint='${JSON.stringify(hint)}' ${userId ? `data-user-id="${userId}"` : ''} data-row-clues='${JSON.stringify(rows)}' data-column-clues='${JSON.stringify(columns)}'></div>`;
    return mount(NonogramPlayer, { attachTo: document.getElementById('nonogram-player')! });
}

function mountColorPlayer(userId?: number) {
    document.body.innerHTML = `<div id="nonogram-player" data-width="2" data-height="1" data-puzzle-id="12" data-revision-id="34" data-mode="color" data-palette='[{"name":"Crimson","hex":"#C0392B"},{"name":"Ocean","hex":"#2471A3"}]' data-progress-url="/puzzles/12/revisions/34/progress" ${userId ? `data-user-id="${userId}"` : ''} data-row-clues='[[[1,1],[1,2]]]' data-column-clues='[[[1,1]],[[1,2]]]' data-start-hint="null"></div>`;
    return mount(NonogramPlayer, { attachTo: document.getElementById('nonogram-player')! });
}

beforeEach(() => { vi.useFakeTimers(); localStorage.clear(); });
afterEach(() => { vi.useRealTimers(); document.body.innerHTML = ''; localStorage.clear(); vi.restoreAllMocks(); vi.unstubAllGlobals(); });

describe('NonogramPlayer', () => {
    it('renders clue-only grid data and supports mark tools and keyboard navigation', async () => {
        const wrapper = mountPlayer();
        await flushPromises();
        expect(wrapper.findAll('[data-player-cell]')).toHaveLength(4);
        expect(wrapper.get('table[aria-label="Nonogram puzzle"]')).toBeTruthy();
        expect(wrapper.get('[role="timer"]').attributes('aria-label')).toBe('Elapsed time');
        expect(wrapper.text()).toContain('Row 1 clue: 1');
        expect(document.body.innerHTML).not.toContain('solution_data');
        expect(document.body.innerHTML).not.toContain('data-solution');

        const first = wrapper.get('[data-player-cell="0"]');
        expect(first.attributes('aria-pressed')).toBe('false');
        await first.trigger('click');
        expect(first.attributes('aria-label')).toBe('Row 1, column 1: filled');
        expect(first.attributes('aria-pressed')).toBe('true');
        await wrapper.findAll('button').find(button => button.text() === 'Erase')!.trigger('click');
        await wrapper.get('[data-player-cell="0"]').trigger('click');
        expect(wrapper.get('[data-player-cell="0"]').attributes('aria-label')).toBe('Row 1, column 1: unknown');

        await wrapper.get('[data-player-cell="0"]').trigger('keydown', { key: 'ArrowRight' });
        expect((document.activeElement as HTMLElement).getAttribute('data-player-cell')).toBe('1');
        wrapper.unmount();
    });

    it('supports keyboard-only movement and direct fill, cross, and erase shortcuts', async () => {
        const wrapper = mountPlayer();
        await flushPromises();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        await flushPromises();
        expect(wrapper.get('[data-player-cell="1"]').attributes('aria-label')).toBe('Row 1, column 2: filled');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'x', bubbles: true }));
        await flushPromises();
        expect(wrapper.get('[data-player-cell="1"]').attributes('aria-label')).toBe('Row 1, column 2: crossed out');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Backspace', bubbles: true }));
        await flushPromises();
        expect(wrapper.get('[data-player-cell="1"]').attributes('aria-label')).toBe('Row 1, column 2: unknown');
        wrapper.unmount();
    });

    it('fills while dragging and crosses with the right mouse button', async () => {
        const wrapper = mountPlayer();
        await flushPromises();

        wrapper.get('[data-player-cell="0"]').element.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true, button: 0 }));
        wrapper.get('[data-player-cell="1"]').element.dispatchEvent(new MouseEvent('pointerenter', { bubbles: true }));
        await flushPromises();
        expect(wrapper.get('[data-player-cell="0"]').attributes('aria-label')).toBe('Row 1, column 1: filled');
        expect(wrapper.get('[data-player-cell="1"]').attributes('aria-label')).toBe('Row 1, column 2: filled');

        wrapper.get('[data-player-cell="2"]').element.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true, button: 2 }));
        await flushPromises();
        expect(wrapper.get('[data-player-cell="2"]').attributes('aria-label')).toBe('Row 2, column 1: crossed out');
        wrapper.unmount();
    });

    it('gives each connected gamepad an independent cursor and shared-board action', async () => {
        const buttons = (pressed: number[]) => Array.from({ length: 16 }, (_, index) => ({ pressed: pressed.includes(index) }));
        const pads = [
            { index: 0, connected: true, buttons: buttons([0, 15]), axes: [0, 0] },
            { index: 1, connected: true, buttons: buttons([0, 13]), axes: [0, 0] },
        ] as unknown as Gamepad[];
        vi.stubGlobal('navigator', Object.assign(Object.create(navigator), { getGamepads: () => pads }));
        const wrapper = mountPlayer();
        await flushPromises();
        await vi.advanceTimersByTimeAsync(100);
        await flushPromises();

        expect(wrapper.get('[data-player-cell="1"] .gamepad-cursor-badge:not([hidden])').text()).toBe('P1');
        expect(wrapper.get('[data-player-cell="2"] .gamepad-cursor-badge:not([hidden])').text()).toBe('P2');
        expect(wrapper.get('[data-player-cell="1"]').attributes('aria-label')).toBe('Row 1, column 2: filled');
        expect(wrapper.get('[data-player-cell="2"]').attributes('aria-label')).toBe('Row 2, column 1: filled');
        wrapper.unmount();
    });

    it('checks filled cells with the server and updates the lives display', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                hardcore: false, lives_remaining: 3, initial_lives: 3, mode_locked: false, game_over: false, guess_count: 0,
            }) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                hardcore: false, lives_remaining: 2, initial_lives: 3, mode_locked: true, game_over: false, guess_count: 1,
            }) } as Response);
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPlayer(2, 2, [[1], [1]], [[1], [1]], undefined, '', null, true);
        await flushPromises();
        expect(wrapper.text()).toContain('Lives: 3 / 3');

        await wrapper.get('[data-player-cell="0"]').trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body))).toMatchObject({ cell_index: 0, guess_id: expect.stringMatching(/^[0-9a-f-]{36}$/) });
        expect(wrapper.text()).toContain('Lives: 2 / 3');
        expect((wrapper.get('[data-player-cell="0"]').element as HTMLButtonElement).disabled).toBe(false);
        wrapper.unmount();
    });

    it('ends an attempt at zero lives and starts a fresh attempt on retry', async () => {
        const state = (lives: number, gameOver: boolean, guesses: number) => ({
            hardcore: false, lives_remaining: lives, initial_lives: 3, mode_locked: guesses > 0, game_over: gameOver, guess_count: guesses,
        });
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => state(3, false, 0) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => state(2, false, 1) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => state(1, false, 2) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => state(0, true, 3) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => state(3, false, 0) } as Response);
        vi.stubGlobal('fetch', fetchMock);
        const base = '/puzzles/12/revisions/34';
        const wrapper = mountPlayer(2, 2, [[1], [1]], [[1], [1]], undefined, base, null, true);
        await flushPromises();
        for (const index of [0, 1, 2]) {
            await wrapper.get(`[data-player-cell="${index}"]`).trigger('click');
            await flushPromises();
        }
        expect(wrapper.text()).toContain('No lives remain');
        expect((wrapper.get('[data-player-cell="3"]').element as HTMLButtonElement).disabled).toBe(true);

        await wrapper.findAll('button').find(button => button.text() === 'Try again')!.trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Lives: 3 / 3');
        expect((wrapper.get('[data-player-cell="0"]').element as HTMLButtonElement).disabled).toBe(false);
        expect(fetchMock.mock.calls[4][1]?.method).toBe('POST');
        wrapper.unmount();
    });

    it('hardcore mode disables auto-cross and keeps the life count off', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                hardcore: false, lives_remaining: 3, initial_lives: 3, mode_locked: false, game_over: false, guess_count: 0,
            }) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                hardcore: true, lives_remaining: null, initial_lives: null, mode_locked: false, game_over: false, guess_count: 0,
            }) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                hardcore: true, lives_remaining: null, initial_lives: null, mode_locked: true, game_over: false, guess_count: 1,
            }) } as Response);
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPlayer(2, 2, [[1], [1]], [[1], [1]], undefined, '', null, true);
        await flushPromises();
        await wrapper.get('.player-hardcore input').setValue(true);
        await flushPromises();
        expect(wrapper.text()).toContain('Lives: off');
        expect((wrapper.get('.player-auto-cross input').element as HTMLInputElement).checked).toBe(false);
        expect((wrapper.get('.player-auto-cross input').element as HTMLInputElement).disabled).toBe(true);

        await wrapper.get('[data-player-cell="0"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-player-cell="1"]').attributes('aria-label')).toBe('Row 1, column 2: unknown');
        expect(fetchMock).toHaveBeenCalledTimes(3);
        wrapper.unmount();
    });

    it('autofills only the server-selected start-hint row and column', async () => {
        const wrapper = mountPlayer(2, 2, [[2], [2]], [[2], [2]], undefined, '', {
            row_index: 0,
            column_index: 1,
            row_cells: '11',
            column_cells: '11',
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Starting hint filled row 1 and column 2');
        expect(wrapper.get('[data-player-cell="0"]').attributes('aria-label')).toBe('Row 1, column 1: filled');
        expect(wrapper.get('[data-player-cell="1"]').attributes('aria-label')).toBe('Row 1, column 2: filled');
        expect(wrapper.get('[data-player-cell="3"]').attributes('aria-label')).toBe('Row 2, column 2: filled');
        expect(wrapper.get('[data-player-cell="2"]').attributes('aria-label')).toBe('Row 2, column 1: unknown');
        wrapper.unmount();
    });

    it('automatically crosses only cells forced empty by a completed clue line', async () => {
        const wrapper = mountPlayer(5, 1, [[1, 1]], [[1], [0], [1], [0], [0]]);
        await flushPromises();
        await wrapper.get('[data-player-cell="0"]').trigger('click');
        await wrapper.get('[data-player-cell="2"]').trigger('click');

        expect(wrapper.get('[data-player-cell="1"]').attributes('aria-label')).toBe('Row 1, column 2: crossed out');
        expect(wrapper.get('[data-player-cell="3"]').attributes('aria-label')).toBe('Row 1, column 4: crossed out');
        expect(wrapper.get('[data-player-cell="4"]').attributes('aria-label')).toBe('Row 1, column 5: crossed out');
        expect(wrapper.findAll('col')).toHaveLength(6);
        expect(wrapper.findAll('col')[1].attributes('style')).toContain('36px');
        wrapper.unmount();
    });

    it('does not auto-cross from an incorrect fill even when the line clue appears satisfied', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                hardcore: false, lives_remaining: 3, initial_lives: 3, mode_locked: false, game_over: false, guess_count: 0,
            }) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                hardcore: false, lives_remaining: 2, initial_lives: 3, mode_locked: true, game_over: false, guess_count: 1,
            }) } as Response);
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPlayer(2, 2, [[1], [1]], [[1], [1]], undefined, '', null, true);
        await flushPromises();
        await wrapper.get('[data-player-cell="0"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-player-cell="1"]').attributes('aria-label')).toBe('Row 1, column 2: unknown');
        expect(wrapper.get('[data-player-cell="2"]').attributes('aria-label')).toBe('Row 2, column 1: unknown');
        wrapper.unmount();
    });

    it('allows manual marking by disabling auto-cross', async () => {
        const wrapper = mountPlayer(5, 1, [[1, 1]], [[1], [1], [1], [1], [1]]);
        await flushPromises();
        await wrapper.get('.player-auto-cross input').setValue(false);
        await wrapper.get('[data-player-cell="0"]').trigger('click');
        await wrapper.get('[data-player-cell="2"]').trigger('click');

        expect(wrapper.get('[data-player-cell="1"]').attributes('aria-label')).toBe('Row 1, column 2: unknown');
        expect(wrapper.get('[data-player-cell="3"]').attributes('aria-label')).toBe('Row 1, column 4: unknown');
        wrapper.unmount();
    });

    it('restores guest progress from local storage only', async () => {
        const first = mountPlayer();
        await flushPromises();
        await first.get('[data-player-cell="0"]').trigger('click');
        expect(localStorage.getItem('nonogram:guest-progress:12:34')).toContain('1220');
        first.unmount();

        const resumed = mountPlayer();
        await flushPromises();
        expect(resumed.get('[data-player-cell="0"]').attributes('aria-label')).toBe('Row 1, column 1: filled');
        resumed.unmount();
    });

    it('allows replaying a verified puzzle locally without changing the original completion', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
            revision_id: 34,
            progress: { marks: '1001', elapsed_seconds: 22, mistake_count: 0, version: 5, completed_at: '2026-11-09T12:00:00Z' },
        }) } as Response);
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPlayer(2, 2, [[1], [1]], [[1], [1]], 77);
        await flushPromises();

        expect((wrapper.get('[data-player-cell="0"]').element as HTMLButtonElement).disabled).toBe(true);
        await wrapper.findAll('button').find(button => button.text() === 'Play again')!.trigger('click');
        expect(wrapper.text()).toContain('Back to completed result');
        await wrapper.get('[data-player-cell="0"]').trigger('click');

        expect((wrapper.get('[data-player-cell="0"]').element as HTMLButtonElement).disabled).toBe(false);
        expect(wrapper.text()).toContain('Replay progress saved on this device only');
        expect(localStorage.getItem('nonogram:replay-progress:77:12:34')).toContain('1000');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it('loads and version-saves signed-in progress without a client-supplied user id', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                revision_id: 34,
                progress: { marks: '2000', elapsed_seconds: 40, mistake_count: 2, version: 4, completed_at: null },
            }) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                revision_id: 34,
                progress: { marks: '0000', elapsed_seconds: 41, mistake_count: 0, version: 5, completed_at: null },
            }) } as Response);
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPlayer(2, 2, [[1], [1]], [[1], [1]], 77, '/Nonogram%20laravel/public');
        await flushPromises();
        expect(wrapper.get('[data-player-cell="0"]').attributes('aria-label')).toBe('Row 1, column 1: crossed out');
        await wrapper.findAll('button').find(button => button.text() === 'Erase')!.trigger('click');
        await wrapper.get('[data-player-cell="0"]').trigger('click');
        await vi.advanceTimersByTimeAsync(900);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[0][0]).toBe('/Nonogram%20laravel/public/puzzles/12/revisions/34/progress');
        expect(fetchMock.mock.calls[1][0]).toBe('/Nonogram%20laravel/public/puzzles/12/revisions/34/progress');
        const save = JSON.parse(String(fetchMock.mock.calls[1][1]?.body));
        expect(save).toMatchObject({ marks: '0000', version: 4 });
        expect(save).not.toHaveProperty('user_id');
        expect(wrapper.text()).toContain('Progress saved to your account');
        wrapper.unmount();
    });

    it('preserves local marks and blocks destructive writes after a progress conflict', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                revision_id: 34,
                progress: { marks: '0000', elapsed_seconds: 0, mistake_count: 0, version: 1, completed_at: null },
            }) } as Response)
            .mockResolvedValueOnce({ ok: false, status: 409, json: async () => ({ current_version: 2 }) } as Response);
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPlayer(2, 2, [[1], [1]], [[1], [1]], 77);
        await flushPromises();
        await wrapper.get('[data-player-cell="0"]').trigger('click');
        await vi.advanceTimersByTimeAsync(900);
        await flushPromises();

        expect(wrapper.text()).toContain('local copy is preserved');
        expect(localStorage.getItem('nonogram:conflict-progress:77:12:34')).toContain('1220');
        expect((wrapper.get('[data-player-cell="1"]').element as HTMLButtonElement).disabled).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        wrapper.unmount();
    });

    it('offers an explicit local-copy restore after reloading a conflict', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                revision_id: 34,
                progress: { marks: '0000', elapsed_seconds: 0, mistake_count: 0, version: 1, completed_at: null },
            }) } as Response)
            .mockResolvedValueOnce({ ok: false, status: 409, json: async () => ({ current_version: 2 }) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                revision_id: 34,
                progress: { marks: '2000', elapsed_seconds: 5, mistake_count: 0, version: 2, completed_at: null },
            }) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                revision_id: 34,
                progress: { marks: '1000', elapsed_seconds: 6, mistake_count: 0, version: 3, completed_at: null },
            }) } as Response);
        vi.stubGlobal('fetch', fetchMock);
        const first = mountPlayer(2, 2, [[1], [1]], [[1], [1]], 77);
        await flushPromises();
        await first.get('[data-player-cell="0"]').trigger('click');
        await vi.advanceTimersByTimeAsync(900);
        await flushPromises();
        first.unmount();

        const resumed = mountPlayer(2, 2, [[1], [1]], [[1], [1]], 77);
        await flushPromises();
        expect(resumed.text()).toContain('local progress copy is available');
        await resumed.get('.conflict-actions button').trigger('click');
        await vi.advanceTimersByTimeAsync(900);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(4);
        expect(JSON.parse(String(fetchMock.mock.calls[3][1]?.body))).toMatchObject({ marks: '1220', version: 2 });
        expect(resumed.text()).toContain('Progress saved to your account');
        expect(localStorage.getItem('nonogram:conflict-progress:77:12:34')).toBeNull();
        resumed.unmount();
    });

    it('automatically completes through the server-verified progress save', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                revision_id: 34,
                progress: { marks: '0', elapsed_seconds: 0, mistake_count: 0, version: 0, completed_at: null },
            }) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                revision_id: 34,
                progress: { marks: '1', elapsed_seconds: 1, mistake_count: 0, version: 1, completed_at: '2026-11-09T12:00:00Z' },
            }) } as Response);
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPlayer(1, 1, [[1]], [[1]], 77);
        await flushPromises();
        await wrapper.get('[data-player-cell="0"]').trigger('click');
        await vi.advanceTimersByTimeAsync(900);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][1]?.method).toBe('PUT');
        expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body)).marks).toBe('1');
        expect(wrapper.text()).toContain('Solved — verified by the server');
        expect(wrapper.find('.candidate-status button').exists()).toBe(false);
        expect(wrapper.findAll('button').some(button => button.text() === 'Play again')).toBe(true);
        expect((wrapper.get('[data-player-cell="0"]').element as HTMLButtonElement).disabled).toBe(true);
        wrapper.unmount();
    });

    it('only presents clue satisfaction as a local candidate, never verified completion', async () => {
        const wrapper = mountPlayer(1, 1, [[1]], [[1]]);
        await flushPromises();
        await wrapper.get('[data-player-cell="0"]').trigger('click');
        expect(wrapper.get('.candidate-status').text()).toContain('All clues match');
        expect(wrapper.get('.candidate-status').text()).toContain('Sign in to save progress');
        wrapper.unmount();
    });

    it('renders named palette clues and fills cells with independent colour IDs', async () => {
        const wrapper = mountColorPlayer();
        await flushPromises();

        expect(wrapper.text()).toContain('Crimson');
        expect(wrapper.text()).toContain('Ocean');
        await wrapper.get('[aria-label="Fill with Crimson, colour 1"]').trigger('click');
        await wrapper.get('[data-player-cell="0"]').trigger('click');
        await wrapper.get('[aria-label="Fill with Ocean, colour 2"]').trigger('click');
        await wrapper.get('[data-player-cell="1"]').trigger('click');

        expect(wrapper.get('[data-player-cell="0"]').attributes('aria-label')).toContain('Crimson (colour 1)');
        expect(wrapper.get('[data-player-cell="1"]').attributes('aria-label')).toContain('Ocean (colour 2)');
        expect(wrapper.get('[data-player-cell="0"]').element.getAttribute('style')).toContain('#C0392B');
        expect(wrapper.get('.candidate-status').text()).toContain('All clues match locally');
        wrapper.unmount();
    });

    it('saves exact Color IDs and locks after server-verified completion', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                revision_id: 34,
                progress: { marks: '00', elapsed_seconds: 0, mistake_count: 0, version: 0, completed_at: null },
            }) } as Response)
            .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({
                revision_id: 34,
                progress: { marks: '12', elapsed_seconds: 1, mistake_count: 0, version: 1, completed_at: '2026-11-11T00:00:00Z' },
            }) } as Response);
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountColorPlayer(77);
        await flushPromises();
        await wrapper.get('[aria-label="Fill with Crimson, colour 1"]').trigger('click');
        await wrapper.get('[data-player-cell="0"]').trigger('click');
        await wrapper.get('[aria-label="Fill with Ocean, colour 2"]').trigger('click');
        await wrapper.get('[data-player-cell="1"]').trigger('click');
        await vi.advanceTimersByTimeAsync(900);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body))).toMatchObject({ marks: '12', version: 0 });
        expect(wrapper.text()).toContain('Solved — verified by the server');
        expect((wrapper.get('[data-player-cell="1"]').element as HTMLButtonElement).disabled).toBe(true);
        wrapper.unmount();
    });

    it('pauses the active-time timer and resumes it', async () => {
        const wrapper = mountPlayer();
        await flushPromises();
        await vi.advanceTimersByTimeAsync(2000);
        expect(wrapper.text()).toContain('00:02');
        await wrapper.findAll('button').find(button => button.text() === 'Pause')!.trigger('click');
        await vi.advanceTimersByTimeAsync(3000);
        expect(wrapper.text()).toContain('00:02');
        await wrapper.findAll('button').find(button => button.text() === 'Resume')!.trigger('click');
        await vi.advanceTimersByTimeAsync(1000);
        expect(wrapper.text()).toContain('00:03');
        wrapper.unmount();
    });
});

// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

describe('optional audio', () => {
    const start = vi.fn();
    const resume = vi.fn().mockResolvedValue(undefined);

    beforeEach(() => {
        vi.useFakeTimers();
        vi.resetModules();
        start.mockClear();
        resume.mockClear();
        document.body.innerHTML = '<button data-audio-sounds aria-pressed="false">Sound effects: off</button><button data-audio-music aria-pressed="false">Background music: off</button><button id="action">Action</button>';
        vi.stubGlobal('AudioContext', class {
            currentTime = 0;
            destination = {};
            resume = resume;
            createOscillator() { return { type: '', frequency: { value: 0 }, connect: vi.fn(), start, stop: vi.fn() }; }
            createGain() { return { gain: { setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() }, connect: vi.fn() }; }
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('only plays effects after they are enabled and stops after they are disabled', async () => {
        await import('./audio');
        const sounds = document.querySelector<HTMLButtonElement>('[data-audio-sounds]')!;
        const action = document.querySelector<HTMLButtonElement>('#action')!;
        action.click();
        expect(start).not.toHaveBeenCalled();

        sounds.click();
        await vi.waitFor(() => expect(sounds.getAttribute('aria-pressed')).toBe('true'));
        action.click();
        expect(start).toHaveBeenCalledTimes(2);
        sounds.click();
        action.click();
        expect(start).toHaveBeenCalledTimes(2);
    });

    it('starts music only on request and stops its timer when switched off', async () => {
        await import('./audio');
        const music = document.querySelector<HTMLButtonElement>('[data-audio-music]')!;
        expect(start).not.toHaveBeenCalled();
        music.click();
        await vi.waitFor(() => expect(music.getAttribute('aria-pressed')).toBe('true'));
        await vi.advanceTimersByTimeAsync(1200);
        expect(start).toHaveBeenCalledTimes(2);
        music.click();
        await vi.advanceTimersByTimeAsync(2400);
        expect(start).toHaveBeenCalledTimes(2);
        expect(music.getAttribute('aria-pressed')).toBe('false');
    });
});

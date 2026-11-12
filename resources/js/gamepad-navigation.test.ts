// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

describe('gamepad navigation', () => {
    const buttons = Array.from({ length: 16 }, () => ({ pressed: false }));
    const pad = { connected: true, mapping: 'standard', axes: [0, 0], buttons };

    beforeEach(() => {
        vi.resetModules();
        vi.useFakeTimers();
        buttons.forEach(button => { button.pressed = false; });
        document.body.innerHTML = '<button id="first">First</button><button id="second">Second</button>';
        vi.spyOn(HTMLElement.prototype, 'getClientRects').mockReturnValue({ length: 1 } as DOMRectList);
        vi.stubGlobal('navigator', { getGamepads: () => [pad] });
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('moves focus and activates a control only after directional navigation', async () => {
        await import('./gamepad-navigation');
        const first = document.querySelector<HTMLButtonElement>('#first')!;
        const second = document.querySelector<HTMLButtonElement>('#second')!;
        const click = vi.fn();
        second.addEventListener('click', click);
        buttons[0]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);
        expect(click).not.toHaveBeenCalled();
        buttons[0]!.pressed = false;
        buttons[13]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);
        expect(document.activeElement).toBe(first);
        buttons[13]!.pressed = false;
        await vi.advanceTimersByTimeAsync(100);
        buttons[13]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);
        expect(document.activeElement).toBe(second);
        buttons[13]!.pressed = false;
        buttons[0]!.pressed = true;
        await vi.advanceTimersByTimeAsync(200);
        expect(click).toHaveBeenCalledTimes(1);
    });

    it('leaves the puzzle player in control of the gamepad', async () => {
        document.body.insertAdjacentHTML('beforeend', '<div id="nonogram-player"></div>');
        await import('./gamepad-navigation');
        buttons[13]!.pressed = true;
        await vi.advanceTimersByTimeAsync(200);
        expect(document.activeElement).toBe(document.body);
    });

    it('activates a focused editor grid with its keyboard paint action', async () => {
        document.body.innerHTML = '<canvas id="grid" role="grid" tabindex="0"></canvas>';
        vi.spyOn(HTMLElement.prototype, 'getClientRects').mockReturnValue({ length: 1 } as DOMRectList);
        vi.resetModules();
        await import('./gamepad-navigation');
        const canvas = document.querySelector<HTMLCanvasElement>('#grid')!;
        const keydown = vi.fn();
        const click = vi.fn();
        canvas.addEventListener('keydown', keydown);
        canvas.addEventListener('click', click);

        buttons[13]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);
        buttons[13]!.pressed = false;
        await vi.advanceTimersByTimeAsync(100);
        expect(document.activeElement).toBe(canvas);
        buttons[0]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);

        expect(keydown).toHaveBeenCalledOnce();
        expect(keydown.mock.calls[0]![0].key).toBe(' ');
        expect(click).not.toHaveBeenCalled();
    });

    it('changes a focused native select option with vertical gamepad input', async () => {
        document.body.innerHTML = '<select id="sort"><option>Newest</option><option>Popular</option></select>';
        vi.resetModules();
        await import('./gamepad-navigation');
        const select = document.querySelector<HTMLSelectElement>('#sort')!;
        const changed = vi.fn();
        select.addEventListener('change', changed);

        buttons[13]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);
        buttons[13]!.pressed = false;
        await vi.advanceTimersByTimeAsync(100);
        expect(document.activeElement).toBe(select);
        buttons[13]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);

        expect(select.value).toBe('Popular');
        expect(changed).toHaveBeenCalledOnce();
        expect(document.activeElement).toBe(select);
    });

    it('keeps gamepad navigation inside an active modal dialog', async () => {
        document.body.innerHTML = '<button id="background">Background action</button><section role="dialog" aria-modal="true"><button id="inside">Dialog action</button></section>';
        vi.resetModules();
        await import('./gamepad-navigation');
        buttons[13]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);

        expect(document.activeElement).toBe(document.querySelector('#inside'));
    });

    it('does not activate a background control with gamepad confirm while a modal is open', async () => {
        document.body.innerHTML = '<button id="background">Background action</button>';
        vi.resetModules();
        await import('./gamepad-navigation');
        const background = document.querySelector<HTMLButtonElement>('#background')!;
        const click = vi.fn();
        background.addEventListener('click', click);
        buttons[15]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);
        buttons[15]!.pressed = false;
        await vi.advanceTimersByTimeAsync(100);
        document.body.insertAdjacentHTML('beforeend', '<section role="dialog" aria-modal="true"><button id="inside">Dialog action</button></section>');
        buttons[0]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);

        expect(click).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(document.querySelector('#inside'));
    });

    it('closes the focused disclosure with gamepad cancel', async () => {
        document.body.innerHTML = '<details open><summary id="menu">Menu</summary><button>Menu action</button></details>';
        vi.resetModules();
        await import('./gamepad-navigation');
        const details = document.querySelector('details')!;
        const summary = document.querySelector<HTMLElement>('#menu')!;
        buttons[15]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);
        buttons[15]!.pressed = false;
        await vi.advanceTimersByTimeAsync(100);
        buttons[1]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);

        expect(details.open).toBe(false);
        expect(document.activeElement).toBe(summary);
    });

    it('sends Escape to an active modal when gamepad cancel is pressed', async () => {
        document.body.innerHTML = '<section role="dialog" aria-modal="true"><button id="inside">Dialog action</button></section>';
        vi.resetModules();
        await import('./gamepad-navigation');
        const escape = vi.fn((event: KeyboardEvent) => event.key === 'Escape');
        window.addEventListener('keydown', escape);
        buttons[15]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);
        buttons[15]!.pressed = false;
        await vi.advanceTimersByTimeAsync(100);
        buttons[1]!.pressed = true;
        await vi.advanceTimersByTimeAsync(100);

        expect(escape).toHaveBeenCalledOnce();
        expect(escape.mock.calls[0]![0].key).toBe('Escape');
    });
});

export {};

const focusableSelector = 'a[href], button:not(:disabled), input:not(:disabled):not([type="hidden"]), select:not(:disabled), textarea:not(:disabled), summary, [tabindex]:not([tabindex="-1"])';

let timer: ReturnType<typeof setInterval> | undefined;
let previousDirection = '';
let nextMoveAt = 0;
let previousConfirm = false;
let previousCancel = false;
let navigationStarted = false;

function focusableElements(): HTMLElement[] {
    const activeDialog = activeModalDialog();
    const scope: ParentNode = activeDialog ?? document;

    return [...scope.querySelectorAll<HTMLElement>(focusableSelector)].filter(element =>
        element.getClientRects().length > 0 && !element.closest('[inert], [aria-hidden="true"]')
        && !element.closest('details:not([open]) > :not(summary)'),
    );
}

function activeModalDialog(): HTMLElement | null {
    return [...document.querySelectorAll<HTMLElement>('[role="dialog"][aria-modal="true"]')]
        .find(dialog => dialog.getClientRects().length > 0) ?? null;
}

function pollGamepad(): void {
    if (document.hidden) {
        previousDirection = '';
        previousConfirm = false;
        previousCancel = false;
        navigationStarted = false;
        return;
    }
    if (document.getElementById('nonogram-player')) return;
    const gamepad = [...navigator.getGamepads()].find(pad => pad?.connected && pad.mapping === 'standard');
    if (!gamepad) {
        previousDirection = '';
        previousConfirm = false;
        previousCancel = false;
        navigationStarted = false;
        return;
    }

    const pressed = (index: number) => gamepad.buttons[index]?.pressed ?? false;
    const vertical = gamepad.axes[1] ?? 0;
    const horizontal = gamepad.axes[0] ?? 0;
    const verticalDirection = (pressed(13) || vertical > 0.6 ? 1 : 0) - (pressed(12) || vertical < -0.6 ? 1 : 0);
    const horizontalDirection = (pressed(15) || horizontal > 0.6 ? 1 : 0) - (pressed(14) || horizontal < -0.6 ? 1 : 0);
    const direction = verticalDirection || horizontalDirection;
    const directionKey = verticalDirection !== 0 ? `vertical:${verticalDirection}`
        : horizontalDirection !== 0 ? `horizontal:${horizontalDirection}` : '';
    const now = Date.now();
    if (direction && (directionKey !== previousDirection || now >= nextMoveAt)) {
        const elements = focusableElements();
        const active = document.activeElement;
        if (active instanceof HTMLSelectElement && verticalDirection !== 0) {
            moveSelectOption(active, verticalDirection);
        } else {
            const current = elements.indexOf(active as HTMLElement);
            const next = current < 0 ? (direction > 0 ? 0 : elements.length - 1) : (current + direction + elements.length) % elements.length;
            const target = elements[next];
            if (target) {
                document.querySelector('.gamepad-focus')?.classList.remove('gamepad-focus');
                target.focus();
                target.classList.add('gamepad-focus');
            }
        }
        navigationStarted = elements.length > 0;
        nextMoveAt = now + 280;
    }
    previousDirection = directionKey;

    const confirm = pressed(0);
    if (confirm && !previousConfirm && navigationStarted) {
        const active = document.activeElement;
        const activeDialog = activeModalDialog();
        if (activeDialog && (!(active instanceof Node) || !activeDialog.contains(active))) {
            const target = focusableElements()[0];
            if (target) {
                document.querySelector('.gamepad-focus')?.classList.remove('gamepad-focus');
                target.focus();
                target.classList.add('gamepad-focus');
            }
        } else if (active instanceof HTMLCanvasElement && active.getAttribute('role') === 'grid') {
            active.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true }));
        } else if (active instanceof HTMLElement && active.matches('a[href], button:not(:disabled), summary, input[type="checkbox"]:not(:disabled), input[type="radio"]:not(:disabled)')) {
            active.click();
        }
    }
    previousConfirm = confirm;

    const cancel = pressed(1);
    if (cancel && !previousCancel && navigationStarted) {
        const activeDialog = activeModalDialog();
        if (activeDialog) {
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        } else {
            const active = document.activeElement;
            const details = active instanceof Element ? active.closest<HTMLDetailsElement>('details[open]')
                : null;
            const activeSummaryIsVisible = details?.querySelector('summary')?.getClientRects().length;
            const openDisclosure = activeSummaryIsVisible
                ? details
                : [...document.querySelectorAll<HTMLDetailsElement>('details[open]')]
                    .filter(disclosure => disclosure.querySelector('summary')?.getClientRects().length)
                    .at(-1);
            if (openDisclosure) {
                const summary = openDisclosure.querySelector<HTMLElement>('summary');
                openDisclosure.open = false;
                summary?.focus();
                summary?.classList.add('gamepad-focus');
            }
        }
    }
    previousCancel = cancel;
}

function moveSelectOption(select: HTMLSelectElement, direction: number): void {
    const options = [...select.options];
    let index = select.selectedIndex;
    if (index < 0) index = direction > 0 ? -1 : options.length;
    do {
        index += direction;
        const option = options[index];
        if (!option) return;
        const disabledGroup = option.parentElement instanceof HTMLOptGroupElement && option.parentElement.disabled;
        if (!option.disabled && !disabledGroup) break;
    } while (true);

    if (index === select.selectedIndex) return;
    select.selectedIndex = index;
    select.dispatchEvent(new Event('input', { bubbles: true }));
    select.dispatchEvent(new Event('change', { bubbles: true }));
}

function startPolling(): void {
    if (!timer && typeof navigator.getGamepads === 'function') timer = setInterval(pollGamepad, 100);
}

window.addEventListener('gamepadconnected', startPolling);
document.addEventListener('focusout', event => {
    if (event.target instanceof HTMLElement) event.target.classList.remove('gamepad-focus');
});
if (typeof navigator.getGamepads === 'function' && [...navigator.getGamepads()].some(pad => pad?.connected)) startPolling();

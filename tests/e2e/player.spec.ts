import { expect, test, type Page } from '@playwright/test';

type GameState = {
    hardcore: boolean;
    lives_remaining: number | null;
    initial_lives: number | null;
    mode_locked: boolean;
    game_over: boolean;
    guess_count: number;
};

function state(hardcore = false, lives: number | null = 3, locked = false, guesses = 0): GameState {
    return { hardcore, lives_remaining: hardcore ? null : lives, initial_lives: hardcore ? null : 3, mode_locked: locked, game_over: false, guess_count: guesses };
}

async function installGameApi(page: Page, initial: GameState = state(), onFill?: (cell: number, current: GameState) => GameState) {
    let current = initial;
    await page.route('**/mock/game-session', route => route.fulfill({ json: current }));
    await page.route('**/mock/game-mode', async route => {
        const body = route.request().postDataJSON() as { hardcore: boolean };
        current = state(body.hardcore);
        await route.fulfill({ json: current });
    });
    await page.route('**/mock/life-check', async route => {
        const body = route.request().postDataJSON() as { cell_index: number; guess_id: string };
        current = onFill ? onFill(body.cell_index, current) : state(current.hardcore, current.lives_remaining, true, current.guess_count + 1);
        await route.fulfill({ json: current });
    });
    await page.route('**/mock/restart', route => route.fulfill({ json: state(current.hardcore) }));
}

test('renders square cells and safely auto-crosses a clue-complete row', async ({ page }) => {
    await installGameApi(page);
    await page.goto('/tests/browser/player.html');

    const cell = page.locator('[data-player-cell="0"]');
    const box = await cell.boundingBox();
    expect(box).not.toBeNull();
    expect(Math.abs(box!.width - box!.height)).toBeLessThan(0.1);

    await cell.click();
    await page.locator('[data-player-cell="2"]').click();
    await expect(page.locator('[data-player-cell="1"]')).toHaveAttribute('aria-label', 'Row 1, column 2: crossed out');
    await expect(page.locator('[data-player-cell="3"]')).toHaveAttribute('aria-label', 'Row 1, column 4: crossed out');
    await expect(page.locator('[data-player-cell="4"]')).toHaveAttribute('aria-label', 'Row 1, column 5: crossed out');
});

test('keyboard navigation moves focus between cells and Space marks the focused cell', async ({ page }) => {
    await installGameApi(page);
    await page.goto('/tests/browser/player.html');

    const firstCell = page.locator('[data-player-cell="0"]');
    const secondCell = page.locator('[data-player-cell="1"]');
    await firstCell.focus();
    await page.keyboard.press('ArrowRight');
    await expect(secondCell).toBeFocused();

    await page.keyboard.press('Space');
    await expect(secondCell).toHaveAttribute('aria-label', 'Row 1, column 2: filled');
});

test('an emulated touchscreen tap marks the selected player cell', async ({ browser }) => {
    const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
        isMobile: true,
        hasTouch: true,
    });

    try {
        const page = await context.newPage();
        await installGameApi(page);
        await page.goto('http://127.0.0.1:5173/tests/browser/player.html');
        const cell = page.locator('[data-player-cell="0"]');
        await cell.tap();

        await expect(cell).toHaveAttribute('aria-label', 'Row 1, column 1: filled');
    } finally {
        await context.close();
    }
});

test('keeps player buttons at least 44 pixels wide and tall on a mobile viewport', async ({ page }) => {
    await installGameApi(page);
    await page.goto('/tests/browser/player.html');
    await expect(page.locator('[data-player-cell="0"]')).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });

    const targetSizes = await page.locator('.player-page button:visible').evaluateAll(buttons => buttons.map(button => {
        const { width, height } = button.getBoundingClientRect();

        return { width, height };
    }));
    expect(targetSizes.length).toBeGreaterThan(0);
    for (const { width, height } of targetSizes) {
        expect(width).toBeGreaterThanOrEqual(44);
        expect(height).toBeGreaterThanOrEqual(44);
    }

    const zoomOut = page.getByRole('button', { name: 'Zoom out' });
    await expect(zoomOut).toBeDisabled();
    await page.setViewportSize({ width: 1280, height: 800 });
    await expect(zoomOut).toBeEnabled();
});

test('preserves forced-colour pressed-state outlines and reduced motion', async ({ page }) => {
    await installGameApi(page);
    await page.goto('/tests/browser/player.html');
    await page.emulateMedia({ forcedColors: 'active', reducedMotion: 'reduce' });

    const pressedTool = page.locator('.player-tools button[aria-pressed="true"]');
    await expect(pressedTool).toHaveCSS('border-top-width', '2px');
    const transitionDuration = await pressedTool.evaluate(button => getComputedStyle(button).transitionDuration);
    expect(Number.parseFloat(transitionDuration)).toBeLessThanOrEqual(0.00001);
});

test('shows server-updated lives after an incorrect fill', async ({ page }) => {
    await installGameApi(page, state(), (_cell, current) => state(false, (current.lives_remaining ?? 1) - 1, true, current.guess_count + 1));
    await page.goto('/tests/browser/player.html');

    await page.locator('[data-player-cell="1"]').click();
    await expect(page.locator('.player-lives')).toHaveText('Lives: 2 / 3');
});

test('Hardcore disables auto-cross and life deductions', async ({ page }) => {
    await installGameApi(page, state(), (_cell, current) => state(true, null, true, current.guess_count + 1));
    await page.goto('/tests/browser/player.html');

    await page.locator('.player-hardcore input').check();
    await expect(page.locator('.player-lives')).toHaveText('Lives: off');
    await expect(page.locator('.player-auto-cross input')).not.toBeChecked();
    await page.locator('[data-player-cell="0"]').click();
    await page.locator('[data-player-cell="2"]').click();

    await expect(page.locator('[data-player-cell="1"]')).toHaveAttribute('aria-label', 'Row 1, column 2: unknown');
    await expect(page.locator('[data-player-cell="4"]')).toHaveAttribute('aria-label', 'Row 1, column 5: unknown');
});

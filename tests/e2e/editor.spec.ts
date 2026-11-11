import { expect, test, type Page, type Route } from '@playwright/test';

type DraftDocument = {
    id: number;
    title: string;
    description: string | null;
    tags: string[];
    version: number;
    updated_at: string | null;
    grid: { version: number; width: number; height: number; cells: string };
};

const WIDTH = 5;
const HEIGHT = 5;

function draft(version = 2, cells = '0'.repeat(WIDTH * HEIGHT)): DraftDocument {
    return {
        id: 7,
        title: 'Browser draft',
        description: null,
        tags: [],
        version,
        updated_at: '2026-11-10T12:00:00+00:00',
        grid: { version: 1, width: WIDTH, height: HEIGHT, cells },
    };
}

async function openEditor(page: Page): Promise<void> {
    await page.goto('/tests/browser/editor.html');
    await expect(page.locator('.save-area [role="status"]')).toContainText('Saved');
}

async function cellCenter(page: Page, index: number) {
    const box = await page.locator('canvas').boundingBox();
    expect(box).not.toBeNull();
    const cellWidth = box!.width / WIDTH;
    const cellHeight = box!.height / HEIGHT;
    const x = index % WIDTH;
    const y = Math.floor(index / WIDTH);

    return { x: box!.x + (x + 0.5) * cellWidth, y: box!.y + (y + 0.5) * cellHeight };
}

async function dragAcross(page: Page, from: number, to: number): Promise<void> {
    const start = await cellCenter(page, from);
    const end = await cellCenter(page, to);
    await page.mouse.move(start.x, start.y);
    await page.mouse.down();
    await page.mouse.move(end.x, end.y, { steps: 10 });
    await page.mouse.up();
}

async function clickCell(page: Page, index: number): Promise<void> {
    await page.locator('.accessible-toggle').click();
    await page.locator(`[data-accessible-cell="${index}"]`).click();
}

test('accessible editor grid supports keyboard navigation and activation', async ({ page }) => {
    draftRoutes(page, { draft: () => draft() });
    await openEditor(page);

    const accessibleToggle = page.locator('.accessible-toggle');
    await accessibleToggle.focus();
    await page.keyboard.press('Enter');
    await expect(accessibleToggle).toHaveAttribute('aria-expanded', 'true');

    const firstCell = page.locator('[data-accessible-cell="0"]');
    const secondCell = page.locator('[data-accessible-cell="1"]');
    await firstCell.focus();
    await page.keyboard.press('ArrowRight');
    await expect(secondCell).toBeFocused();

    await page.keyboard.press('Enter');
    await expect(secondCell).toHaveAttribute('aria-label', 'Row 1, column 2: filled');
});

test('keeps editor controls and accessible cells at least 44 pixels wide and tall on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    draftRoutes(page, { draft: () => draft() });
    await openEditor(page);
    await page.locator('.accessible-toggle').click();

    const targetSizes = await page.locator('.editor-page button:visible').evaluateAll(buttons => buttons.map(button => {
        const { width, height } = button.getBoundingClientRect();

        return { width, height };
    }));
    expect(targetSizes.length).toBeGreaterThan(0);
    for (const { width, height } of targetSizes) {
        expect(width).toBeGreaterThanOrEqual(44);
        expect(height).toBeGreaterThanOrEqual(44);
    }
});

test('scopes touch-action suppression to the canvas and accepts a touch stroke', async ({ browser }) => {
    const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
        isMobile: true,
        hasTouch: true,
    });

    try {
        const page = await context.newPage();
        draftRoutes(page, { draft: () => draft() });
        await openEditor(page);

        const canvas = page.locator('.editor-page canvas');
        const canvasBox = await canvas.boundingBox();
        expect(canvasBox).not.toBeNull();
        await canvas.tap({
            position: { x: canvasBox!.width / (WIDTH * 2), y: canvasBox!.height / (HEIGHT * 2) },
        });

        const touchActions = await page.locator('.editor-page').evaluate(editor => ({
            canvas: getComputedStyle(editor.querySelector('canvas')!).touchAction,
            editor: getComputedStyle(editor).touchAction,
        }));

        expect(touchActions.canvas).toBe('none');
        expect(touchActions.editor).toBe('auto');
        await page.locator('.accessible-toggle').click();
        await expect(page.locator('[data-accessible-cell="0"]')).toHaveAttribute('aria-label', 'Row 1, column 1: filled');
    } finally {
        await context.close();
    }
});

test('renders the maximum 30-by-30 accessible grid within two seconds', async ({ page }) => {
    draftRoutes(page, { draft: () => draft() });
    await openEditor(page);

    const width = page.getByLabel('Width');
    await width.fill('30');
    await width.press('Tab');
    const height = page.getByLabel('Height');
    await height.fill('30');
    await height.press('Tab');

    const renderStartedAt = await page.evaluate(() => performance.now());
    await page.locator('.accessible-toggle').click();
    await expect(page.locator('[data-accessible-cell="899"]')).toBeAttached();
    const renderDuration = await page.evaluate(startedAt => performance.now() - startedAt, renderStartedAt);

    await expect(page.locator('.accessible-grid [data-accessible-cell]')).toHaveCount(900);
    expect(renderDuration).toBeLessThan(2000);
});

function draftRoutes(page: Page, options: {
    draft: () => DraftDocument;
    onUpdate?: (route: Route) => Promise<void>;
}): void {
    page.route('**/mock/drafts/**', async route => {
        if (route.request().method() === 'GET') {
            await route.fulfill({ json: options.draft() });

            return;
        }

        if (options.onUpdate) {
            await options.onUpdate(route);

            return;
        }

        await route.fulfill({ json: options.draft() });
    });
}

test('dragging paints a run, updates clues, and autosaves the grid', async ({ page }) => {
    let saved: { version: number; cells: string } | null = null;
    draftRoutes(page, {
        draft: () => draft(),
        onUpdate: async route => {
            const body = route.request().postDataJSON() as { version: number; grid: { cells: string } };
            saved = { version: body.version, cells: body.grid.cells };
            await route.fulfill({ json: draft(body.version + 1, body.grid.cells) });
        },
    });

    await openEditor(page);
    await dragAcross(page, 0, 2);

    const strayCell = await cellCenter(page, 4);
    await page.mouse.move(strayCell.x, strayCell.y);

    await page.locator('.accessible-toggle').click();

    await expect(page.locator('[data-accessible-cell="0"]')).toHaveAttribute('aria-label', 'Row 1, column 1: filled');
    await expect(page.locator('[data-accessible-cell="1"]')).toHaveAttribute('aria-label', 'Row 1, column 2: filled');
    await expect(page.locator('[data-accessible-cell="2"]')).toHaveAttribute('aria-label', 'Row 1, column 3: filled');
    await expect(page.locator('[data-accessible-cell="3"]')).toHaveAttribute('aria-label', 'Row 1, column 4: empty');
    await expect(page.locator('.row-clues .clue-line').first()).toHaveText('3');

    await expect(page.locator('[data-accessible-cell="4"]')).toHaveAttribute('aria-label', 'Row 1, column 5: empty');

    await expect.poll(() => saved).not.toBeNull();
    expect(saved!.version).toBe(2);
    expect(saved!.cells).toBe('1110000000000000000000000');
    await expect(page.locator('.save-area [role="status"]')).toContainText('Saved');
});

test('pointer hit testing stays accurate after zooming', async ({ page }) => {
    draftRoutes(page, { draft: () => draft() });

    await openEditor(page);
    await page.getByRole('button', { name: 'Zoom in' }).click();
    await page.getByRole('button', { name: 'Zoom in' }).click();
    await expect(page.locator('.zoom-controls')).toContainText('Zoom 120%');

    await dragAcross(page, 6, 6);
    await page.locator('.accessible-toggle').click();

    await expect(page.locator('[data-accessible-cell="6"]')).toHaveAttribute('aria-label', 'Row 2, column 2: filled');
    await expect(page.locator('[data-accessible-cell="5"]')).toHaveAttribute('aria-label', 'Row 2, column 1: empty');
    await expect(page.locator('[data-accessible-cell="7"]')).toHaveAttribute('aria-label', 'Row 2, column 3: empty');
    await expect(page.locator('[data-accessible-cell="1"]')).toHaveAttribute('aria-label', 'Row 1, column 2: empty');
});

test('erase and clear update the grid and participate in undo and redo', async ({ page }) => {
    draftRoutes(page, { draft: () => draft() });

    await openEditor(page);
    await page.locator('.accessible-toggle').click();
    await page.locator('[data-accessible-cell="0"]').click();
    await page.locator('[data-accessible-cell="1"]').click();
    await page.locator('[data-accessible-cell="2"]').click();

    await page.getByRole('button', { name: 'Erase', exact: true }).click();
    await page.locator('[data-accessible-cell="1"]').click();
    await expect(page.locator('[data-accessible-cell="0"]')).toHaveAttribute('aria-label', 'Row 1, column 1: filled');
    await expect(page.locator('[data-accessible-cell="1"]')).toHaveAttribute('aria-label', 'Row 1, column 2: empty');
    await expect(page.locator('[data-accessible-cell="2"]')).toHaveAttribute('aria-label', 'Row 1, column 3: filled');

    await page.getByRole('button', { name: 'Clear grid' }).click();
    await expect(page.locator('[data-accessible-cell="0"]')).toHaveAttribute('aria-label', 'Row 1, column 1: empty');
    await expect(page.locator('[data-accessible-cell="2"]')).toHaveAttribute('aria-label', 'Row 1, column 3: empty');

    await page.getByRole('button', { name: 'Undo' }).click();
    await expect(page.locator('[data-accessible-cell="0"]')).toHaveAttribute('aria-label', 'Row 1, column 1: filled');
    await expect(page.locator('[data-accessible-cell="1"]')).toHaveAttribute('aria-label', 'Row 1, column 2: empty');
    await expect(page.locator('[data-accessible-cell="2"]')).toHaveAttribute('aria-label', 'Row 1, column 3: filled');

    await page.getByRole('button', { name: 'Redo' }).click();
    await expect(page.locator('[data-accessible-cell="0"]')).toHaveAttribute('aria-label', 'Row 1, column 1: empty');
    await expect(page.locator('[data-accessible-cell="2"]')).toHaveAttribute('aria-label', 'Row 1, column 3: empty');
});

test('a new edit after undo discards the redo branch', async ({ page }) => {
    draftRoutes(page, { draft: () => draft() });

    await openEditor(page);
    await page.locator('.accessible-toggle').click();
    await page.locator('[data-accessible-cell="0"]').click();

    await page.getByRole('button', { name: 'Undo' }).click();
    await expect(page.getByRole('button', { name: 'Redo' })).toBeEnabled();
    await page.locator('[data-accessible-cell="2"]').click();

    await expect(page.locator('[data-accessible-cell="0"]')).toHaveAttribute('aria-label', 'Row 1, column 1: empty');
    await expect(page.locator('[data-accessible-cell="1"]')).toHaveAttribute('aria-label', 'Row 1, column 2: empty');
    await expect(page.locator('[data-accessible-cell="2"]')).toHaveAttribute('aria-label', 'Row 1, column 3: filled');
    await expect(page.getByRole('button', { name: 'Redo' })).toBeDisabled();
});

test('a stale save conflict keeps local edits and stops destructive retries', async ({ page }) => {
    let updates = 0;
    draftRoutes(page, {
        draft: () => draft(),
        onUpdate: async route => {
            updates++;
            await route.fulfill({ status: 409, json: { message: 'This draft changed elsewhere.' } });
        },
    });

    await openEditor(page);
    await clickCell(page, 6);

    await expect(page.locator('.save-area [role="status"]')).toContainText('Conflict');
    await expect(page.getByRole('button', { name: 'Restore local copy' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save draft' })).toBeDisabled();

    await page.waitForTimeout(1500);
    expect(updates).toBe(1);

    const cached = await page.evaluate(() => Object.keys(localStorage));
    expect(cached.some(key => key.startsWith('nonogram:'))).toBe(true);
});

test('a rejected publication shows the server reason and keeps the draft editable', async ({ page }) => {
    page.on('dialog', dialog => dialog.accept());
    draftRoutes(page, { draft: () => draft() });
    await page.route('**/mock/publish/7', route => route.fulfill({
        status: 422,
        json: { message: 'This puzzle has multiple solutions (ambiguous).', uniqueness: 'ambiguous' },
    }));

    await openEditor(page);
    await page.getByRole('button', { name: 'Publish' }).click();

    await expect(page.locator('.save-area [role="status"]')).toContainText('multiple solutions');
    await expect(page).toHaveURL(/\/tests\/browser\/editor\.html/);

    await clickCell(page, 12);
    await expect(page.locator('[data-accessible-cell="12"]')).toHaveAttribute('aria-label', 'Row 3, column 3: filled');
});

test('opening a draft does not rewrite it', async ({ page }) => {
    let updates = 0;
    draftRoutes(page, {
        draft: () => draft(),
        onUpdate: async route => {
            updates++;
            await route.fulfill({ json: draft(3) });
        },
    });

    await openEditor(page);
    await page.waitForTimeout(1200);

    expect(updates).toBe(0);
    await expect(page.locator('.save-area [role="status"]')).toContainText('Saved');
    await expect(page.getByRole('button', { name: 'Publish' })).toBeEnabled();
});

test('unsaved work is restored automatically after a reload at the same version', async ({ page }) => {
    draftRoutes(page, {
        draft: () => draft(),
        onUpdate: route => route.fulfill({ status: 500, json: { message: 'Save failed' } }),
    });

    await openEditor(page);
    await clickCell(page, 0);
    await expect(page.locator('.save-area [role="status"]')).toContainText('edits kept locally');

    await page.reload();
    await page.locator('.accessible-toggle').click();

    await expect(page.locator('[data-accessible-cell="0"]')).toHaveAttribute('aria-label', 'Row 1, column 1: filled');
});

test('recovers an edit after navigating away while its autosave request is pending', async ({ page }) => {
    let notifyUpdateStarted!: () => void;
    const updateStarted = new Promise<void>(resolve => { notifyUpdateStarted = resolve; });
    let allowFirstUpdateToFinish!: () => void;
    const firstUpdateMayFinish = new Promise<void>(resolve => { allowFirstUpdateToFinish = resolve; });
    let notifyFirstUpdateFinished!: () => void;
    const firstUpdateFinished = new Promise<void>(resolve => { notifyFirstUpdateFinished = resolve; });
    let updates = 0;

    draftRoutes(page, {
        draft: () => draft(),
        onUpdate: async route => {
            updates++;
            if (updates === 1) {
                notifyUpdateStarted();
                await firstUpdateMayFinish;
                await route.abort().catch(() => undefined);
                notifyFirstUpdateFinished();

                return;
            }

            const body = route.request().postDataJSON() as { grid: { cells: string } };
            await route.fulfill({ json: draft(3, body.grid.cells) });
        },
    });

    await openEditor(page);
    await clickCell(page, 6);
    await updateStarted;
    await expect(page.locator('.save-area [role="status"]')).toContainText('Autosaving');
    expect(await page.evaluate(() => Object.keys(localStorage).some(key => key.startsWith('nonogram:draft:')))).toBe(true);

    await page.goto('/tests/browser/player.html');
    allowFirstUpdateToFinish();
    await firstUpdateFinished;
    await page.goto('/tests/browser/editor.html');
    await expect(page.locator('.save-area [role="status"]')).toContainText('Recovered unsaved local edits');
    await page.locator('.accessible-toggle').click();

    await expect(page.locator('[data-accessible-cell="6"]')).toHaveAttribute('aria-label', 'Row 2, column 2: filled');
    await expect.poll(() => updates).toBe(2);
    await expect(page.locator('.save-area [role="status"]')).toContainText('Saved');
});

test('a newer server draft requires explicit confirmation before restoring local work', async ({ page }) => {
    let version = 2;
    draftRoutes(page, {
        draft: () => draft(version),
        onUpdate: route => route.fulfill({ status: 500, json: { message: 'Save failed' } }),
    });

    await openEditor(page);
    await clickCell(page, 1);
    await expect(page.locator('.save-area [role="status"]')).toContainText('edits kept locally');

    version = 3;
    page.on('dialog', dialog => dialog.accept());
    await page.reload();

    await expect(page.getByRole('button', { name: 'Restore local copy' })).toBeVisible();
    await page.locator('.accessible-toggle').click();
    await expect(page.locator('[data-accessible-cell="1"]')).toHaveAttribute('aria-label', 'Row 1, column 2: empty');

    await page.getByRole('button', { name: 'Restore local copy' }).click();

    await expect(page.locator('[data-accessible-cell="1"]')).toHaveAttribute('aria-label', 'Row 1, column 2: filled');
});

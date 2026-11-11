import { expect, test } from '@playwright/test';

test('an authenticated author publishes, moderates, and completes a puzzle through Laravel', async ({ page }) => {
    const title = `Browser integration ${Date.now()}`;

    await page.goto('/login');
    await page.getByLabel('Email').fill('test@example.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto('/editor/new');
    await expect(page.locator('.editor-page')).toBeVisible();
    await page.getByLabel('Width').fill('1');
    await page.getByLabel('Width').press('Tab');
    await page.getByLabel('Height').fill('1');
    await page.getByLabel('Height').press('Tab');
    await page.getByLabel('Title').fill(title);
    await page.locator('.accessible-toggle').click();
    await page.locator('[data-accessible-cell="0"]').click();
    await expect(page.locator('.save-area [role="status"]')).toContainText('Saved');
    await expect(page.getByRole('button', { name: 'Publish' })).toBeEnabled();

    page.on('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Publish' }).click();
    await page.waitForURL(/\/puzzles\/\d+$/);
    const puzzleUrl = new URL(page.url());

    await page.goto('/moderation');
    const puzzleCard = page.locator('li').filter({ has: page.getByRole('heading', { name: title }) });
    await expect(puzzleCard).toBeVisible();
    await puzzleCard.getByRole('button', { name: 'Approve' }).click();
    await expect(page.getByRole('status')).toContainText('Moderation decision saved');

    await page.goto(puzzleUrl.toString());
    const cell = page.locator('[data-player-cell="0"]');
    await expect(cell).toBeEnabled();
    await cell.click();
    await expect(page.getByText('Puzzle completion verified by the server.')).toBeVisible();
    await expect(page.locator('.progress-status')).toContainText('Solved — verified by the server');

    await page.reload();
    await expect(page.getByText('Puzzle completion verified by the server.')).toBeVisible();
    await expect(page.locator('.progress-status')).toContainText('already verified as complete');
});

test('the image importer fits a mobile viewport and transfers into a saved draft', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('test@example.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/editor/import');
    const imageBytes = await page.evaluate(async () => {
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('A 2D canvas context is unavailable.');
        context.fillStyle = '#000000';
        context.fillRect(0, 0, 1, 1);
        const blob = await new Promise<Blob>((resolve, reject) => {
            canvas.toBlob(image => image ? resolve(image) : reject(new Error('Could not encode the image fixture.')), 'image/png');
        });

        return Array.from(new Uint8Array(await blob.arrayBuffer()));
    });
    await page.getByLabel('Source image').setInputFiles({
        name: 'single-pixel.png',
        mimeType: 'image/png',
        buffer: Buffer.from(imageBytes),
    });
    await expect(page.getByText('single-pixel.png · 1×1')).toBeVisible();
    await page.getByLabel('Grid width').fill('1');
    await page.getByLabel('Grid width').press('Tab');
    await page.getByLabel('Grid height').fill('1');
    await page.getByLabel('Grid height').press('Tab');
    await page.getByRole('button', { name: 'Update preview now' }).click();
    const generatedGrid = page.getByRole('img', { name: 'Generated 1 by 1 monochrome grid' });
    await expect(generatedGrid).toBeVisible();
    await expect(page.getByText('1×1 ·')).toBeVisible();
    const mobileLayout = await page.locator('main').evaluate(main => {
        const viewportWidth = document.documentElement.clientWidth;
        const visibleControls = Array.from(main.querySelectorAll<HTMLElement>('input, select, button, [role="img"], img'))
            .filter(element => element.getClientRects().length > 0)
            .map(element => {
                const { left, right } = element.getBoundingClientRect();

                return { left, right };
            });

        return {
            viewportWidth,
            documentWidth: document.documentElement.scrollWidth,
            visibleControls,
        };
    });
    expect(mobileLayout.viewportWidth).toBe(390);
    expect(mobileLayout.documentWidth).toBeLessThanOrEqual(mobileLayout.viewportWidth);
    for (const { left, right } of mobileLayout.visibleControls) {
        expect(left).toBeGreaterThanOrEqual(0);
        expect(right).toBeLessThanOrEqual(mobileLayout.viewportWidth + 0.5);
    }
    const generatedCellClass = await generatedGrid.locator('span').getAttribute('class');
    expect(generatedCellClass).not.toBeNull();
    const generatedCellIsFilled = generatedCellClass!.includes('bg-slate-900');

    await page.getByRole('button', { name: 'Edit puzzle' }).click();
    await expect(page.locator('.editor-page')).toBeVisible();
    await expect(page.getByLabel('Title')).toHaveValue('Imported image puzzle');
    await expect(page.locator('.save-area [role="status"]')).toContainText('Image grid imported. Save to create a draft.');
    await page.locator('.accessible-toggle').click();
    const importedCell = page.locator('[data-accessible-cell="0"]');
    await expect(importedCell).toHaveAttribute('aria-label', generatedCellIsFilled
        ? 'Row 1, column 1: filled'
        : 'Row 1, column 1: empty');

    const draftResponsePromise = page.waitForResponse(response => response.url().endsWith('/puzzles/drafts')
        && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Save draft' }).click();
    const draftResponse = await draftResponsePromise;
    expect(draftResponse.status()).toBe(201);
    const createdDraft = await draftResponse.json() as { id: number };
    expect(createdDraft.id).toBeGreaterThan(0);
    await expect(page.locator('.save-area [role="status"]')).toContainText('Saved');

    await page.reload();
    await expect(page.getByLabel('Title')).toHaveValue('Imported image puzzle');
    await page.locator('.accessible-toggle').click();
    await expect(page.locator('[data-accessible-cell="0"]')).toHaveAttribute('aria-label', generatedCellIsFilled
        ? 'Row 1, column 1: filled'
        : 'Row 1, column 1: empty');
});

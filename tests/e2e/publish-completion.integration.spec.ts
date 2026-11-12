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
    const importerPostRequests: string[] = [];
    page.on('request', request => {
        if (request.method() === 'POST') {
            importerPostRequests.push(request.url());
        }
    });

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

    const draftResponsePromise = page.waitForResponse(response => response.url().endsWith('/puzzles/drafts')
        && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Create draft and edit' }).click();
    const draftResponse = await draftResponsePromise;
    expect(draftResponse.status()).toBe(201);
    expect(importerPostRequests).toHaveLength(1);
    await expect(page.locator('.editor-page')).toBeVisible();
    await expect(page.getByLabel('Title')).toHaveValue('single-pixel');
    await page.locator('.accessible-toggle').click();
    const importedCell = page.locator('[data-accessible-cell="0"]');
    await expect(importedCell).toHaveAttribute('aria-label', generatedCellIsFilled
        ? 'Row 1, column 1: filled'
        : 'Row 1, column 1: empty');

    expect(page.url()).toMatch(/\/editor\/\d+$/);

    await page.reload();
    await expect(page.getByLabel('Title')).toHaveValue('single-pixel');
    await page.locator('.accessible-toggle').click();
    await expect(page.locator('[data-accessible-cell="0"]')).toHaveAttribute('aria-label', generatedCellIsFilled
        ? 'Row 1, column 1: filled'
        : 'Row 1, column 1: empty');
});

test('JPEG and WebP files decode locally and generate image-import previews', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('test@example.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto('/editor/import');
    const encodedImages = await page.evaluate(async () => {
        const canvas = document.createElement('canvas');
        canvas.width = 2;
        canvas.height = 1;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('A 2D canvas context is unavailable.');
        context.fillStyle = '#000000';
        context.fillRect(0, 0, 1, 1);
        context.fillStyle = '#ffffff';
        context.fillRect(1, 0, 1, 1);

        const encode = async (mimeType: 'image/jpeg' | 'image/webp') => {
            const blob = await new Promise<Blob>((resolve, reject) => {
                canvas.toBlob(image => image ? resolve(image) : reject(new Error(`Could not encode ${mimeType} fixture.`)), mimeType);
            });
            if (blob.type !== mimeType) throw new Error(`This browser cannot encode ${mimeType}.`);

            return { mimeType, bytes: Array.from(new Uint8Array(await blob.arrayBuffer())) };
        };

        return [await encode('image/jpeg'), await encode('image/webp')];
    });

    for (const { mimeType, bytes } of encodedImages) {
        const extension = mimeType === 'image/jpeg' ? 'jpg' : 'webp';
        const fileName = `sample.${extension}`;
        await page.getByLabel('Source image').setInputFiles({
            name: fileName,
            mimeType,
            buffer: Buffer.from(bytes),
        });

        await expect(page.getByText(`${fileName} · 2×1`)).toBeVisible();
        const updatePreview = page.getByRole('button', { name: 'Update preview now' });
        await expect(updatePreview).toBeEnabled();
        await updatePreview.click();
        await expect(page.getByRole('img', { name: 'Generated 10 by 10 monochrome grid' })).toBeVisible();
        await expect(page.getByRole('alert')).toHaveCount(0);
    }
});

for (const standard of [false, true]) {
    test(`a color image imports, publishes, and reveals after completion as ${standard ? 'standard' : 'Color Picross'}`, async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email').fill('test@example.com');
        await page.getByLabel('Password').fill('password');
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page).toHaveURL(/\/dashboard$/);
        await page.goto('/editor/import');
        await page.setViewportSize({ width: 390, height: 844 });
        const imageBytes = await page.evaluate(async () => {
            const canvas = document.createElement('canvas');
            canvas.width = 3;
            canvas.height = 1;
            const context = canvas.getContext('2d')!;
            context.fillStyle = '#FF0000';
            context.fillRect(0, 0, 1, 1);
            context.fillStyle = '#0000FF';
            context.fillRect(1, 0, 1, 1);
            const blob = await new Promise<Blob>(resolve => canvas.toBlob(blob => resolve(blob!), 'image/png'));
            return Array.from(new Uint8Array(await blob.arrayBuffer()));
        });
        await page.getByLabel('Puzzle mode').selectOption('color');
        await page.getByLabel('Source image').setInputFiles({ name: 'color.png', mimeType: 'image/png', buffer: Buffer.from(imageBytes) });
        await expect(page.getByText('color.png · 3×1')).toBeVisible();
        await page.getByLabel('Grid width').fill('3');
        await page.getByLabel('Grid height').fill('1');
        await page.getByRole('button', { name: 'Update preview now' }).click();
        await expect(page.getByRole('img', { name: 'Generated 3 by 1 color grid' })).toBeVisible();
        await expect(page.getByLabel('Generated color palette')).toContainText('#FF0000');
        if (standard) await page.getByLabel('Create a standard version of this color puzzle').check();
        await page.getByRole('button', { name: 'Analyze uniqueness', exact: true }).click();
        await expect(page.getByText('Client uniqueness result: unique.')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);

        const title = `Imported ${standard ? 'standard' : 'color'} ${Date.now()}`;
        await page.getByLabel('Puzzle title').fill(title);
        const saving = page.waitForResponse(response => response.url().endsWith('/puzzles/drafts') && response.request().method() === 'POST');
        await page.getByRole('button', { name: 'Create draft and edit' }).click();
        const response = await saving;
        expect(response.status()).toBe(201);
        await page.waitForURL(/\/editor\/\d+$/);
        const draftId = new URL(page.url()).pathname.split('/').pop();
        const stored = await page.request.get(`/puzzles/drafts/${draftId}`);
        expect(stored.status()).toBe(200);
        const saved = await stored.json() as { grid: { mode?: string; cells: string; palette?: Array<{ name: string; hex: string }> } };
        expect(saved.grid.cells[2]).toBe('0');
        if (standard) {
            expect(saved.grid.cells).toBe('110');
            expect(saved.grid.mode).not.toBe('color');
        } else {
            expect(saved.grid.mode).toBe('color');
            expect(saved.grid.palette?.map(entry => entry.hex).sort()).toEqual(['#0000FF', '#FF0000']);
        }
        await expect(page.locator('.editor-page')).toBeVisible();
        await page.reload();
        await expect(page.getByLabel('Title', { exact: true })).toHaveValue(title);
        const publish = page.getByRole('button', { name: 'Publish', exact: true });
        await expect(publish).toBeEnabled();
        page.on('dialog', dialog => dialog.accept());
        await publish.click();
        await page.waitForURL(/\/puzzles\/\d+$/);
        const puzzleUrl = page.url();
        await page.goto('/moderation');
        await page.locator('li').filter({ has: page.getByRole('heading', { name: title }) }).getByRole('button', { name: 'Approve' }).click();
        await page.goto(puzzleUrl);
        await expect(page.locator('.player-artwork-overlay')).toHaveCount(0);
        await page.getByRole('button', { name: standard ? 'Cross out' : 'Cross out ×', exact: true }).click();
        await page.locator('[data-player-cell="2"]').click();
        if (standard) await page.getByRole('button', { name: 'Fill', exact: true }).click();
        for (const index of [0, 1]) {
            if (!standard) {
                const colorId = Number(saved.grid.cells[index]);
                await page.getByRole('button', { name: `Fill with ${saved.grid.palette![colorId - 1].name}, colour ${colorId}` }).click();
            }
            await page.locator(`[data-player-cell="${index}"]`).click();
        }
        await expect(page.locator('.progress-status')).toContainText('Solved — verified by the server');
        await expect(page.locator('.player-artwork-overlay img')).toBeVisible();
        await expect.poll(() => page.locator('.player-artwork-overlay img').evaluate(image => (image as HTMLImageElement).naturalWidth)).toBeGreaterThan(0);
    });
}

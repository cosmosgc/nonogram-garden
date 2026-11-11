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

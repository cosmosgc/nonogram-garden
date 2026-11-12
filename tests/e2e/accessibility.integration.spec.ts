import { expect, test } from '@playwright/test';

test('desktop gamepad navigation reaches the visible main navigation links', async ({ page }) => {
    await page.addInitScript(() => {
        const gamepad = {
            connected: true,
            mapping: 'standard',
            axes: [0, 0],
            buttons: Array.from({ length: 16 }, () => ({ pressed: false })),
        };
        Object.defineProperty(navigator, 'getGamepads', { configurable: true, value: () => [gamepad] });
    });
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('/login');
    const menuLink = page.getByRole('link', { name: 'Browse puzzles' });
    await expect(menuLink).toBeVisible();
    await expect(page.locator('.site-menu')).toHaveAttribute('open', '');
    await page.evaluate(() => window.dispatchEvent(new Event('gamepadconnected')));

    const pressDown = async () => {
        await page.evaluate(() => {
            const gamepad = navigator.getGamepads()[0]!;
            (gamepad.buttons[13] as GamepadButton & { pressed: boolean }).pressed = true;
        });
        await page.waitForTimeout(120);
        await page.evaluate(() => {
            const gamepad = navigator.getGamepads()[0]!;
            (gamepad.buttons[13] as GamepadButton & { pressed: boolean }).pressed = false;
        });
        await page.waitForTimeout(120);
    };

    let reachedNavigationLink = false;
    for (let index = 0; index < 20; index++) {
        await pressDown();
        if (await menuLink.evaluate(element => element === document.activeElement)) {
            reachedNavigationLink = true;
            break;
        }
    }

    expect(reachedNavigationLink).toBe(true);
});

test('navigation follows the desktop and mobile breakpoints and remains operable', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/login');
    const menu = page.locator('.site-menu');

    await expect(menu).not.toHaveAttribute('open', '');
    await expect(menu.locator('summary')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Browse puzzles' })).toBeHidden();
    await menu.locator('summary').click();
    await expect(page.getByRole('link', { name: 'Browse puzzles' })).toBeVisible();

    await page.setViewportSize({ width: 1280, height: 800 });
    await expect(menu).toHaveAttribute('open', '');
    await expect(page.getByRole('link', { name: 'Browse puzzles' })).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(menu).not.toHaveAttribute('open', '');
});

test('account deletion dialog is named and restores focus to its trigger after Escape', async ({ page }) => {
    await page.addInitScript(() => {
        const gamepad = {
            connected: true,
            mapping: 'standard',
            axes: [0, 0],
            buttons: Array.from({ length: 16 }, () => ({ pressed: false })),
        };
        Object.defineProperty(navigator, 'getGamepads', { configurable: true, value: () => [gamepad] });
        Object.defineProperty(window, '__testGamepad', { configurable: true, value: gamepad });
    });
    await page.goto('/login');
    await page.getByLabel('Email').fill('test@example.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/profile');
    await expect(page.locator('.profile-card').first()).toHaveCSS('background-color', 'rgb(255, 253, 248)');
    await expect(page.getByRole('textbox', { name: 'Name', exact: true })).toHaveCSS('min-height', '44px');
    await expect(page.getByRole('button', { name: 'Save' }).first()).toHaveCSS('background-color', 'rgb(35, 107, 80)');

    const trigger = page.getByRole('button', { name: 'Delete Account', exact: true });
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: 'Are you sure you want to delete your account?' });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    await page.keyboard.press('Escape');

    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();

    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Password')).toBeFocused();
    const setGamepadButton = async (index: number, pressed: boolean) => page.evaluate(({ buttonIndex, isPressed }) => {
        const gamepad = (window as Window & { __testGamepad: Gamepad }).__testGamepad;
        (gamepad.buttons[buttonIndex] as GamepadButton & { pressed: boolean }).pressed = isPressed;
    }, { buttonIndex: index, isPressed: pressed });
    await setGamepadButton(15, true);
    await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused();
    await setGamepadButton(15, false);
    await setGamepadButton(1, true);

    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
    await setGamepadButton(1, false);
});

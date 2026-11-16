const { test, expect } = require('@playwright/test');

const site = 'http://localhost:5173';
const ADMIN = { email: 'ci.admin@example.test', password: 'CiAdminPassword123!' };
const STAFF = { email: 'ci.staff@example.test', password: 'CiStaffPassword123!' };

async function login(page, account) {
  await page.goto(site + '/#login');
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
  await page.locator('#login-email').fill(account.email);
  await page.locator('#login-password').fill(account.password);
  await page.getByRole('button', { name: /Sign In/i }).click();
}

test('admin can log in, open every team page, and log out', async ({ page }) => {
  await login(page, ADMIN);

  await expect(page).toHaveURL(/#users$/);
  await expect(page.getByRole('heading', { name: 'Users & Access' })).toBeVisible();

  await page.locator('a[href="#fees"]').click();
  await expect(page.getByRole('heading', { name: 'Monthly Fee Management' })).toBeVisible();

  await page.locator('a[href="#kitchens"]').click();
  await expect(page.getByRole('heading', { name: 'Shared Kitchen Records' })).toBeVisible();

  await page.locator('a[href="#account"]').click();
  await expect(page.getByRole('heading', { name: 'My Account' })).toBeVisible();

  await page.getByRole('button', { name: 'Logout' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();

  await page.goto(site + '/#users');
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
});

test('staff can log in but cannot open user administration page', async ({ page }) => {
  await login(page, STAFF);

  await expect(page).toHaveURL(/#allocations$/);
  await expect(page.locator('a[href="#users"]')).toHaveCount(0);

  await page.goto(site + '/#users');
  await expect(page.getByText('Admin access required.')).toBeVisible();
  await page.locator('a[href="#allocations"]').click();

  await page.locator('a[href="#account"]').click();
  await expect(page.getByRole('heading', { name: 'My Account' })).toBeVisible();
  await page.getByRole('button', { name: 'Logout' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
});

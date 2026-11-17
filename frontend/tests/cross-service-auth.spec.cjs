const { test, expect } = require('@playwright/test');

const site = 'http://localhost:5173';

// This smoke test uses two real servers: the central Laravel identity service
// and the native PHP backend. The native backend intentionally has NO
// DATABASE_URL in CI. A verified session must reach its 503 fail-closed DB
// configuration guard; anonymous requests must be denied before DB access.
test('native API checks live central session and CSRF for writes', async ({ page }) => {
  await page.goto(site + '/#login');

  let status = await page.evaluate(async () => {
    const r = await fetch('/native-api/kitchens', { credentials: 'same-origin' });
    return r.status;
  });
  expect(status).toBe(401);

  await page.locator('#login-email').fill('ci.staff@example.test');
  await page.locator('#login-password').fill('CiStaffPassword123!');
  await page.getByRole('button', { name: /Sign In/i }).click();
  await expect(page).toHaveURL(/#allocations$/);

  status = await page.evaluate(async () => {
    const r = await fetch('/native-api/kitchens', { credentials: 'same-origin' });
    return r.status;
  });
  expect(status).toBe(503); // Auth passed; native DB intentionally unconfigured.

  status = await page.evaluate(async () => {
    const r = await fetch('/native-api/kitchens', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    });
    return r.status;
  });
  expect(status).toBe(419);

  status = await page.evaluate(async () => {
    const tokenResponse = await fetch('/laravel/csrf-token', { credentials: 'same-origin' });
    const { csrf_token } = await tokenResponse.json();
    const r = await fetch('/native-api/kitchens', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf_token },
      body: '{}',
    });
    return r.status;
  });
  expect(status).toBe(503); // Session+CSRF accepted before DB configuration check.

  await page.getByRole('button', { name: 'Logout' }).click();
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();

  status = await page.evaluate(async () => {
    const r = await fetch('/native-api/kitchens', { credentials: 'same-origin' });
    return r.status;
  });
  expect(status).toBe(401);
});

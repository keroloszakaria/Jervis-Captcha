import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

async function select(page: Page, kind: string) {
  await page.goto('/');
  if (kind !== 'text') await page.locator('#kind').selectOption(kind);
  await expect.poll(() => page.locator('#captcha').evaluate((node: any) => ({ type: node.challenge?.type, status: node.status, working: node.working }))).toEqual({ type: kind, status: 'ready', working: false });
}
async function solution(page: Page): Promise<any> {
  const token = await page.locator('#captcha').evaluate((node: any) => node.sessionToken);
  const response = await page.request.post('/__test/answer', { headers: { Authorization: 'Bearer fixture-oracle-only' }, data: { token } });
  expect(response.ok()).toBeTruthy(); return response.json();
}
async function answer(page: Page, kind: string) {
  const a = await solution(page);
  if (['text', 'math', 'logic', 'audio'].includes(kind)) await page.locator('jervis-captcha input[type=text]').fill(a.text);
  for (const [field, label] of [['x', 'Horizontal position'], ['y', 'Vertical position'], ['angle', 'Rotation angle']]) {
    if ((kind === 'slider' && field === 'x') || (kind === 'drag' && field !== 'angle') || (kind === 'rotate' && field === 'angle')) {
      await page.getByLabel(label!).evaluate((node: HTMLInputElement, value) => { node.value = String(value); node.dispatchEvent(new Event('input', { bubbles: true })); }, a[field!]);
    }
  }
  if (kind === 'click') {
    const board = page.locator('jervis-captcha .board'); const rect = await board.boundingBox();
    for (const point of a.points) await board.click({ position: { x: point.x * rect!.width / 300, y: point.y * rect!.height / 160 } });
  }
  await page.getByRole('button', { name: 'Verify', exact: true }).click();
  await expect(page.locator('jervis-captcha .success')).toBeVisible();
}
for (const kind of ['text', 'slider', 'drag', 'rotate', 'click', 'audio', 'math', 'logic']) {
  test(`${kind}: real engine, widget and protected host action`, async ({ page }) => {
    await select(page, kind); await answer(page, kind);
    const proof = await page.locator('#captcha').evaluate((node: any) => node.token);
    await page.getByRole('button', { name: 'Submit protected action' }).click();
    await expect(page.locator('#result')).toContainText('Protected action accepted');
    const replay = await page.request.post('/demo/submit', { headers: { Origin: 'http://127.0.0.1:8099' }, data: { token: proof } });
    expect(replay.status()).toBe(403);
  });
}
test('managed frontend package bootstraps its own embedded session', async ({ page }) => {
  await page.goto('/');
  await page.evaluate(() => {
    const element = document.createElement('jervis-captcha') as any;
    element.id = 'managed-captcha'; element.action = 'demo'; element.endpoint = '/jervis-captcha/'; element.presentation = 'invisible';
    document.body.append(element);
  });
  await expect.poll(() => page.locator('#managed-captcha').evaluate((node: any) => node.token)).toMatch(/^[a-f0-9]{64}\.[A-Za-z0-9_-]{43}$/);
  await expect.poll(() => page.locator('#managed-captcha').evaluate((node: any) => node.sessionToken)).toBe('');
});

test('bounded work runs offline and consumes a proof', async ({ page }) => {
  const external: string[] = [];
  await page.route('**/*', route => { const url = new URL(route.request().url()); if (!['127.0.0.1', 'localhost'].includes(url.hostname)) { external.push(url.href); return route.abort(); } return route.continue(); });
  await page.goto('/'); await page.locator('#presentation').selectOption('invisible'); await page.locator('#kind').selectOption('pow');
  await expect(page.getByRole('button', { name: 'Submit protected action' })).toBeEnabled();
  await page.getByRole('button', { name: 'Submit protected action' }).click();
  await expect(page.locator('#result')).toContainText('Protected action accepted'); expect(external).toEqual([]);
});
test('incorrect answers never unlock the protected action', async ({ page }) => {
  await select(page, 'text'); await page.locator('jervis-captcha input[type=text]').fill('WRONG'); await page.getByRole('button', { name: 'Verify', exact: true }).click();
  await expect(page.locator('jervis-captcha .error')).toContainText('did not match'); await expect(page.locator('#submit')).toBeDisabled();
});
test('proof expiry clears form value and parent state', async ({ page }) => {
  await select(page, 'text'); await answer(page, 'text'); await expect(page.locator('#submit')).toBeEnabled();
  await expect(page.locator('#submit')).toBeDisabled({ timeout: 8000 }); expect(await page.locator('#captcha').evaluate((node: any) => node.token)).toBe('');
});
test('Arabic RTL, keyboard control and reduced motion', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' }); await select(page, 'slider'); await page.locator('#locale').selectOption('ar');
  await expect(page.locator('jervis-captcha section')).toHaveAttribute('dir', 'rtl');
  const range = page.getByLabel('الموضع الأفقي'); await range.focus(); await range.press('ArrowRight'); expect(await range.inputValue()).toBe('1');
  await page.screenshot({ path: 'test-results/arabic-slider.png', fullPage: true });
});
test('checkbox does not pass verification merely on click', async ({ page }) => {
  await page.goto('/'); await page.locator('#presentation').selectOption('checkbox');
  await page.getByRole('checkbox', { name: 'Start verification' }).click();
  await expect(page.locator('jervis-captcha input[type=text]')).toBeVisible(); await expect(page.locator('#submit')).toBeDisabled();
});
test('a new session invalidates the old UI proof immediately', async ({ page }) => {
  await select(page, 'text'); await answer(page, 'text'); await page.locator('#renew').click();
  await expect(page.locator('#submit')).toBeDisabled(); await expect.poll(() => page.locator('#captcha').evaluate((node: any) => node.token)).toBe('');
});

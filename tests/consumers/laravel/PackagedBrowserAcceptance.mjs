import { chromium } from '@playwright/test';

const base = new URL(process.argv[2] ?? '');
if (base.protocol !== 'http:' || base.hostname !== '127.0.0.1' || !base.port) {
  throw new Error('Usage: node PackagedBrowserAcceptance.mjs http://127.0.0.1:<port>/');
}

const browser = await chromium.launch({ headless: true });
try {
  const context = await browser.newContext();
  const page = await context.newPage();
  const external = [];
  page.on('request', request => {
    const target = new URL(request.url());
    if (target.origin !== base.origin) external.push(request.url());
  });

  const response = await page.goto(new URL('/__jervis/browser', base).href, { waitUntil: 'domcontentloaded' });
  if (!response?.ok()) throw new Error(`Browser fixture returned ${response?.status()}`);
  await page.locator('jervis-captcha').waitFor({ state: 'attached' });
  await page.waitForFunction(() => /^[a-f0-9]{64}\.[A-Za-z0-9_-]{43}$/.test(document.querySelector('jervis-captcha')?.token ?? ''), null, { timeout: 15000 });

  const proof = await page.locator('jervis-captcha').evaluate(node => node.token);
  const scriptSrc = await page.locator('script[src*="jervis-captcha"]').getAttribute('src');
  if (!scriptSrc) throw new Error('Package browser asset was not rendered');
  const asset = await context.request.get(new URL(scriptSrc, base).href);
  if (!asset.ok()) throw new Error(`Package browser asset returned ${asset.status()}`);
  const headers = asset.headers();
  if ((headers['x-content-type-options'] ?? '').toLowerCase() !== 'nosniff') throw new Error('Missing nosniff on package asset');
  if ((headers['cross-origin-resource-policy'] ?? '').toLowerCase() !== 'same-origin') throw new Error('Missing same-origin CORP on package asset');

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.locator('#submit').click(),
  ]);
  if ((await page.locator('#result').textContent())?.trim() !== 'accepted') throw new Error('Protected form was not accepted');
  if (external.length !== 0) throw new Error(`Unexpected external browser requests: ${external.join(', ')}`);

  console.log(JSON.stringify({
    ok: true,
    proofFormat: /^[a-f0-9]{64}\.[A-Za-z0-9_-]{43}$/.test(proof),
    protectedSubmit: true,
    zeroEgress: true,
    assetHardening: true,
  }));
} finally {
  await browser.close();
}

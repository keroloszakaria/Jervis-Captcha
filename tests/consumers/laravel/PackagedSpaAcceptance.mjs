import { chromium } from '@playwright/test';

const base = new URL(process.argv[2] ?? '');
if (base.protocol !== 'http:' || base.hostname !== '127.0.0.1' || !base.port) {
  throw new Error('Usage: node PackagedSpaAcceptance.mjs http://127.0.0.1:<port>/');
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

  const response = await page.goto(new URL('/__jervis/spa', base).href, { waitUntil: 'domcontentloaded' });
  if (!response?.ok()) throw new Error(`SPA fixture returned ${response?.status()}`);
  const element = page.locator('jervis-captcha');
  await element.waitFor({ state: 'attached' });
  await page.waitForFunction(() => /^[a-f0-9]{64}\.[A-Za-z0-9_-]{43}$/.test(document.querySelector('jervis-captcha')?.token ?? ''), null, { timeout: 15000 });

  const scriptSrc = await page.locator('script[type="module"]').getAttribute('src');
  if (scriptSrc !== '/__jervis/frontend/jervis-captcha.js') throw new Error(`Unexpected SPA package entry: ${scriptSrc}`);
  const proof = await element.evaluate(node => node.token);

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.locator('#submit').click(),
  ]);
  if ((await page.locator('#result').textContent())?.trim() !== 'accepted') throw new Error('SPA protected form was not accepted');
  if (external.length !== 0) throw new Error(`Unexpected external SPA requests: ${external.join(', ')}`);

  console.log(JSON.stringify({
    ok: true,
    proofFormat: /^[a-f0-9]{64}\.[A-Za-z0-9_-]{43}$/.test(proof),
    frontendArtifact: true,
    protectedSubmit: true,
    zeroEgress: true,
  }));
} finally {
  await browser.close();
}

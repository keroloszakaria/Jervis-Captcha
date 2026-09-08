import { test as base, expect } from '@playwright/test';

/** Every browser test uses fresh context storage and rejects nonlocal requests. */
export const test = base.extend<{ egressGuard: void }>({
  egressGuard: [async ({ context }, use) => {
    const external: string[] = [];
    await context.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.origin !== 'http://127.0.0.1:8099') {
        external.push(url.origin);
        await route.abort('blockedbyclient');
      } else {
        await route.continue();
      }
    });
    await use();
    expect(external, 'The widget must not request external resources').toEqual([]);
  }, { auto: true }],
});
export { expect };

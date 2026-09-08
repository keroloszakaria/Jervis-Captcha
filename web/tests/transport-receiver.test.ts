import { describe, expect, it } from 'vitest';
import { CaptchaClient } from '../src/client';

describe('browser fetch receiver', () => {
  it('keeps the global receiver for both public API requests', async () => {
    const calls: string[] = [];
    const proof = { token: 'a'.repeat(64) + '.' + 'b'.repeat(43), expiresInMs: 30_000 };
    const challenge = { id: 'c'.repeat(43), type: 'text', prompt: 'Read the image', expiresInMs: 60_000, minimumDelayMs: 0 };
    const transport: typeof fetch = function (this: unknown, input) {
      if (this !== globalThis) throw new TypeError('Illegal invocation');
      const path = new URL(String(input)).pathname;
      calls.push(path);
      return Promise.resolve(new Response(JSON.stringify(path.endsWith('/challenges') ? challenge : proof), {
        status: 200, headers: { 'Content-Type': 'application/json' },
      }));
    };
    const client = new CaptchaClient('http://localhost/captcha/', transport);
    await expect(client.issue('session-token')).resolves.toEqual(challenge);
    await expect(client.solve('session-token', challenge.id, { text: 'answer' })).resolves.toEqual(proof);
    expect(calls).toEqual(['/captcha/v1/challenges', '/captcha/v1/answers']);
  });
});

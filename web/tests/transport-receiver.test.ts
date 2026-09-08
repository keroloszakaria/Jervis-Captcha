import { describe, expect, it } from 'vitest';
import { CaptchaClient } from '../src/client';

describe('browser fetch receiver', () => {
  it('keeps the global receiver for embedded session, issue and solve requests', async () => {
    const calls: string[] = [];
    const bodies: unknown[] = [];
    const session = { sessionToken: 's'.repeat(43), expiresInMs: 300_000, accessibilityAvailable: true, fieldName: 'captchaProof' };
    const proof = { token: 'a'.repeat(64) + '.' + 'b'.repeat(43), expiresInMs: 30_000 };
    const challenge = { id: 'c'.repeat(43), type: 'math', prompt: '2 + 3 = ?', expiresInMs: 60_000, minimumDelayMs: 0 };
    const transport: typeof fetch = function (this: unknown, input, init) {
      if (this !== globalThis) throw new TypeError('Illegal invocation');
      const path = new URL(String(input)).pathname; calls.push(path);
      bodies.push(init?.body ? JSON.parse(String(init.body)) : null);
      const data = path.includes('/session/') ? session : path.endsWith('/challenges') ? challenge : proof;
      return Promise.resolve(new Response(JSON.stringify(data), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    };
    const client = new CaptchaClient('http://localhost/captcha/', transport);
    await expect(client.createSession('signup', 'accessible')).resolves.toEqual(session);
    await expect(client.issue(session.sessionToken)).resolves.toEqual(challenge);
    await expect(client.solve(session.sessionToken, challenge.id, { text: 'answer' })).resolves.toEqual(proof);
    expect(calls).toEqual(['/captcha/session/signup', '/captcha/v1/challenges', '/captcha/v1/answers']);
    expect(bodies[0]).toEqual({ variant: 'accessible' });
  });
});

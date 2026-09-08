import { describe, it, expect, vi } from 'vitest';
import { createHash } from 'node:crypto';
import { CaptchaClient, CaptchaError } from '../src/client';
import { sha256, workMatches } from '../src/sha256';
const token = 'a'.repeat(43);
const challenge = { id: token, type: 'math', prompt: '2 + 3 = ?', expiresInMs: 120000, minimumDelayMs: 0 };
const json = (data: unknown, status = 200) => new Response(JSON.stringify(data), { status });

describe('SHA-256 proof of work', () => {
  it('matches the platform implementation for independent test vectors', () => {
    for (const value of ['', 'abc', 'hello', 'a'.repeat(55), ...Array.from({ length: 100 }, (_, i) => `${token}:${i}`)]) {
      const actual = Array.from(sha256(value)).map(n => n.toString(16).padStart(8, '0')).join('');
      expect(actual).toBe(createHash('sha256').update(value).digest('hex'));
    }
  });
  it('rejects unsupported message lengths and encodings', () => { expect(() => sha256('x'.repeat(56))).toThrow(); expect(() => sha256('مرحبا')).toThrow(); });
  it('uses leading bits rather than decimal prefixes', () => {
    let nonce = 0; while (!workMatches(token, String(nonce), 12)) nonce++;
    expect(createHash('sha256').update(`${token}:${nonce}`).digest()[0]).toBe(0);
    expect(workMatches(token, String(nonce), 21)).toBe(false);
  });
});
describe('public browser client', () => {
  it('preserves reverse-proxy prefixes and never sends credentials', async () => {
    const mock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => json(challenge)); const client = new CaptchaClient('https://intranet.test/captcha/', mock);
    await client.issue(token); expect(String(mock.mock.calls[0]![0])).toBe('https://intranet.test/captcha/v1/challenges');
    const init = mock.mock.calls[0]![1] as RequestInit; expect(init.credentials).toBe('omit'); expect(init.redirect).toBe('error'); expect(JSON.stringify(init)).not.toContain('Authorization');
  });
  it('rejects dangerous endpoint schemes and embedded credentials', () => {
    for (const endpoint of ['javascript:alert(1)', 'https://u:p@intranet.test', 'https://intranet.test/?secret=x']) expect(() => new CaptchaClient(endpoint)).toThrow();
  });
  it('does not retry a verification request', async () => {
    const mock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => json({ code: 'invalid_proof' }, 403)); const client = new CaptchaClient('/', mock);
    await expect(client.solve(token, token, { text: 'ABCDE' })).rejects.toMatchObject({ code: 'invalid_proof', status: 403 }); expect(mock).toHaveBeenCalledTimes(1);
  });
  it('rejects external media URLs and out-of-budget work', async () => {
    for (const bad of [{ ...challenge, image: 'https://tracker.invalid/pixel' }, { ...challenge, work: { seed: token, bits: 30 } }, { ...challenge, type: 'biometric' }]) {
      await expect(new CaptchaClient('/', async () => json(bad)).issue(token)).rejects.toBeInstanceOf(CaptchaError);
    }
  });
  it('fails closed on malformed responses', async () => {
    await expect(new CaptchaClient('/', async () => new Response('oops')).issue(token)).rejects.toMatchObject({ code: 'invalid_response' });
    await expect(new CaptchaClient('/', async () => json({ token: 'fake', expiresInMs: 1000 })).solve(token, token, {})).rejects.toMatchObject({ code: 'invalid_response' });
  });
  it('propagates cancellation without a retry', async () => {
    const controller = new AbortController(); controller.abort();
    const transport: typeof fetch = async (_url, init) => { expect(init?.signal?.aborted).toBe(true); throw new Error('aborted'); };
    await expect(new CaptchaClient('/', transport).issue(token, controller.signal)).rejects.toMatchObject({ code: 'request_cancelled' });
  });
});

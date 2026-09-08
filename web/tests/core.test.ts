import { describe, it, expect, vi } from 'vitest';
import { createHash } from 'node:crypto';
import { CaptchaClient, CaptchaError } from '../src/client';
import { sha256, workMatches } from '../src/sha256';
const token = 'a'.repeat(43);
const challenge = { id: token, type: 'math', prompt: '2 + 3 = ?', expiresInMs: 120000, minimumDelayMs: 0 };
const managedSession = { sessionToken: token, expiresInMs: 300000, accessibilityAvailable: true, fieldName: 'jervisCaptchaToken' };
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
  it('preserves reverse-proxy prefixes and uses same-origin-only browser credentials', async () => {
    const mock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => json(challenge)); const client = new CaptchaClient('https://intranet.test/captcha/', mock);
    await client.issue(token); expect(String(mock.mock.calls[0]![0])).toBe('https://intranet.test/captcha/v1/challenges');
    const init = mock.mock.calls[0]![1] as RequestInit; expect(init.credentials).toBe('same-origin'); expect(init.redirect).toBe('error'); expect(JSON.stringify(init)).not.toContain('Authorization');
  });
  it('creates managed sessions using only server-approved variants', async () => {
    const mock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => json(managedSession));
    const client = new CaptchaClient('https://intranet.test/jervis-captcha/', mock);
    await expect(client.createSession('signup')).resolves.toEqual(managedSession);
    expect(String(mock.mock.calls[0]![0])).toBe('https://intranet.test/jervis-captcha/session/signup');
    expect(JSON.parse(String((mock.mock.calls[0]![1] as RequestInit).body))).toEqual({ variant: 'standard' });

    mock.mockClear();
    await expect(client.createSession('signup', 'accessible')).resolves.toEqual(managedSession);
    expect(JSON.parse(String((mock.mock.calls[0]![1] as RequestInit).body))).toEqual({ variant: 'accessible' });

    await expect(client.createSession('../admin')).rejects.toMatchObject({ code: 'invalid_context' });
    await expect(client.createSession('signup', 'audio' as any)).rejects.toMatchObject({ code: 'invalid_context' });
    expect(mock).toHaveBeenCalledTimes(1);
  });
  it('fails closed when managed capability or field metadata is malformed', async () => {
    for (const response of [
      { sessionToken: token, expiresInMs: 300000 },
      { ...managedSession, accessibilityAvailable: 'yes' },
      { ...managedSession, fieldName: 'captcha.proof' },
      { ...managedSession, fieldName: '' },
    ]) {
      const client = new CaptchaClient('https://intranet.test/jervis-captcha/', async () => json(response));
      await expect(client.createSession('signup')).rejects.toMatchObject({ code: 'invalid_response' });
    }
  });
  it('sends CSRF to same-origin embedded routes and rejects cross-origin managed bootstrap', async () => {
    const mock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => json(managedSession));
    vi.stubGlobal('location', new URL('https://intranet.test/form'));
    await new CaptchaClient('/jervis-captcha/', mock, 'csrf-value').createSession('signup');
    expect((mock.mock.calls[0]![1] as RequestInit).headers).toMatchObject({ 'X-CSRF-TOKEN': 'csrf-value' });
    mock.mockClear();
    await expect(new CaptchaClient('https://other.test/jervis-captcha/', mock, 'csrf-value').createSession('signup'))
      .rejects.toMatchObject({ code: 'managed_session_requires_same_origin' });
    expect(mock).not.toHaveBeenCalled();
    vi.unstubAllGlobals();
  });
  it('rejects dangerous endpoint schemes and embedded credentials', () => {
    for (const endpoint of ['javascript:alert(1)', 'https://u:p@intranet.test', 'https://intranet.test/?secret=x']) expect(() => new CaptchaClient(endpoint)).toThrow();
  });
  it('does not retry a verification request', async () => {
    const mock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => json({ code: 'invalid_proof' }, 403)); const client = new CaptchaClient('/', mock);
    await expect(client.solve(token, token, { text: 'ABCDE' })).rejects.toMatchObject({ code: 'invalid_proof', status: 403 }); expect(mock).toHaveBeenCalledTimes(1);
  });
  it('accepts the native drag shape with an omitted zero pieceTop', async () => {
    const drag = {
      id: token, type: 'drag', prompt: 'drag', expiresInMs: 120000, minimumDelayMs: 0,
      image: 'data:image/png;base64,AA==', piece: 'data:image/png;base64,AA==',
      width: 300, height: 160, pieceSize: 44,
    };
    await expect(new CaptchaClient('/', async () => json(drag)).issue(token)).resolves.toEqual(drag);
  });
  it('rejects type-specific challenge payloads missing required media or geometry', async () => {
    const cases = [
      { id: token, type: 'text', prompt: 'text', expiresInMs: 120000, minimumDelayMs: 0 },
      { id: token, type: 'rotate', prompt: 'rotate', expiresInMs: 120000, minimumDelayMs: 0 },
      { id: token, type: 'audio', prompt: 'audio', expiresInMs: 120000, minimumDelayMs: 0 },
      { id: token, type: 'slider', prompt: 'slider', expiresInMs: 120000, minimumDelayMs: 0, image: 'data:image/png;base64,AA==', piece: 'data:image/png;base64,AA==', width: 300, height: 160, pieceSize: 44 },
      { id: token, type: 'drag', prompt: 'drag', expiresInMs: 120000, minimumDelayMs: 0, image: 'data:image/png;base64,AA==', piece: 'data:image/png;base64,AA==' },
      { id: token, type: 'click', prompt: 'click', expiresInMs: 120000, minimumDelayMs: 0, image: 'data:image/png;base64,AA==' },
      { id: token, type: 'pow', prompt: 'pow', expiresInMs: 120000, minimumDelayMs: 0 },
    ];
    for (const bad of cases) {
      await expect(new CaptchaClient('/', async () => json(bad as any)).issue(token)).rejects.toMatchObject({ code: 'invalid_response' });
    }
  });
  it('rejects external media URLs, malformed tokens and out-of-budget work', async () => {
    for (const bad of [
      { ...challenge, image: 'https://tracker.invalid/pixel' },
      { ...challenge, work: { seed: token, bits: 30 } },
      { ...challenge, work: { seed: '!'.repeat(43), bits: 12 } },
      { ...challenge, id: '!'.repeat(43) },
      { ...challenge, type: 'biometric' },
    ]) {
      await expect(new CaptchaClient('/', async () => json(bad as any)).issue(token)).rejects.toBeInstanceOf(CaptchaError);
    }
  });
  it('fails closed on malformed and oversized responses', async () => {
    await expect(new CaptchaClient('/', async () => new Response('oops')).issue(token)).rejects.toMatchObject({ code: 'invalid_response' });
    await expect(new CaptchaClient('/', async () => json({ token: 'fake', expiresInMs: 1000 })).solve(token, token, {})).rejects.toMatchObject({ code: 'invalid_response' });
    await expect(new CaptchaClient('/', async () => new Response('{}', { headers: { 'Content-Length': '1000001' } })).issue(token)).rejects.toMatchObject({ code: 'invalid_response' });
  });
  it('rejects malformed request tokens before transport', async () => {
    const mock = vi.fn(async () => json(challenge));
    const client = new CaptchaClient('/', mock);
    await expect(client.issue('bad')).rejects.toMatchObject({ code: 'invalid_session' });
    await expect(client.solve(token, 'bad', {})).rejects.toMatchObject({ code: 'invalid_challenge' });
    expect(mock).not.toHaveBeenCalled();
  });
  it('propagates cancellation without a retry', async () => {
    const controller = new AbortController(); controller.abort();
    const transport: typeof fetch = async (_url, init) => { expect(init?.signal?.aborted).toBe(true); throw new Error('aborted'); };
    await expect(new CaptchaClient('/', transport).issue(token, controller.signal)).rejects.toMatchObject({ code: 'request_cancelled' });
  });
});

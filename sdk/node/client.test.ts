import { it, expect, vi } from 'vitest';
import { JervisCaptchaServer } from './client';
const opts = { endpoint: 'http://127.0.0.1:8090/', siteKey: 'app', secret: 's'.repeat(43) };
it('authenticates the private host request without browser origins', async () => {
  const transport = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(JSON.stringify({ sessionToken: 'a'.repeat(43), expiresInMs: 300000 })));
  await new JervisCaptchaServer({ ...opts, transport }).createSession({ action: 'signup', binding: 'server-session-binding' });
  const init = transport.mock.calls[0]![1] as RequestInit;
  expect(init.headers).toMatchObject({ Authorization: `Bearer ${opts.secret}` }); expect(init.redirect).toBe('error');
  expect(JSON.parse(init.body as string)).toMatchObject({ action: 'signup', siteKey: 'app', binding: 'server-session-binding' });
});
it('rejects a response for a different action', async () => {
  const transport = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(JSON.stringify({ success: true, action: 'transfer' })));
  await expect(new JervisCaptchaServer({ ...opts, transport }).verify({ action: 'signup', token: 'proof', binding: 'binding' })).rejects.toMatchObject({ code: 'invalid_response' }); expect(transport).toHaveBeenCalledTimes(1);
});
it('fails closed when the service is unavailable', async () => {
  const transport = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => { throw new Error('unavailable'); });
  await expect(new JervisCaptchaServer({ ...opts, transport }).verify({ action: 'signup', token: 'proof', binding: 'binding' })).rejects.toMatchObject({ code: 'service_unavailable' }); expect(transport).toHaveBeenCalledTimes(1);
});

export interface Session { sessionToken: string; expiresInMs: number }
export interface Verification { success: true; action: string }
export interface ServerOptions { endpoint: string; siteKey: string; secret: string; timeoutMs?: number; transport?: typeof fetch }
export class VerificationError extends Error {
  constructor(public readonly code: string, public readonly status: number) { super(code); this.name = 'VerificationError'; }
}
/** Server-only. Never import configured instances into a browser bundle. */
export class JervisCaptchaServer {
  private readonly base: URL;
  constructor(private readonly options: ServerOptions) {
    this.base = new URL(options.endpoint.endsWith('/') ? options.endpoint : options.endpoint + '/');
    if (!['http:', 'https:'].includes(this.base.protocol) || this.base.username || this.base.password || this.base.search || this.base.hash || !options.siteKey || options.secret.length < 32) throw new Error('Invalid Jervis Captcha server configuration');
  }
  private async post<T>(path: string, body: unknown): Promise<T> {
    const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), this.options.timeoutMs ?? 5000);
    try {
      const r = await (this.options.transport ?? fetch)(new URL(path, this.base), {
        method: 'POST', redirect: 'error', signal: controller.signal, cache: 'no-store',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${this.options.secret}` },
        body: JSON.stringify({ ...body as object, siteKey: this.options.siteKey }),
      });
      const data = await r.json() as T & { code?: string };
      if (!r.ok) throw new VerificationError(data?.code ?? 'verification_failed', r.status);
      return data;
    } catch (error) { if (error instanceof VerificationError) throw error; throw new VerificationError('service_unavailable', 503); }
    finally { clearTimeout(timer); }
  }
  async createSession(input: { action: string; binding: string; type?: string }): Promise<Session> {
    const s = await this.post<Session>('v1/sessions', input);
    if (!s || !/^[A-Za-z0-9_-]{43}$/.test(s.sessionToken) || !Number.isFinite(s.expiresInMs) || s.expiresInMs <= 0) throw new VerificationError('invalid_response', 503);
    return s;
  }
  async verify(input: { token: string; action: string; binding: string }): Promise<Verification> {
    const v = await this.post<Verification>('v1/siteverify', input);
    if (!v || v.success !== true || v.action !== input.action) throw new VerificationError('invalid_response', 503);
    return v;
  }
}

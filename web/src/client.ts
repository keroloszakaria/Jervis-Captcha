export type ChallengeType = 'text' | 'slider' | 'drag' | 'rotate' | 'click' | 'audio' | 'math' | 'logic' | 'pow';
export type SessionVariant = 'standard' | 'accessible';
export interface Point { x: number; y: number }
export interface Answer { text?: string; x?: number; y?: number; angle?: number; points?: Point[] }
export interface Work { seed: string; bits: number }
export interface Challenge {
  id: string; type: ChallengeType; prompt: string; image?: string; piece?: string; audio?: string;
  width?: number; height?: number; pieceSize?: number; pieceTop?: number;
  expiresInMs: number; minimumDelayMs: number; work?: Work;
}
export interface Proof { token: string; expiresInMs: number }
export interface CaptchaSession { sessionToken: string; expiresInMs: number; accessibilityAvailable: boolean }
export class CaptchaError extends Error {
  constructor(public readonly code: string, public readonly status = 0) { super(code); this.name = 'CaptchaError'; }
}
const kinds = new Set(['text', 'slider', 'drag', 'rotate', 'click', 'audio', 'math', 'logic', 'pow']);
function validChallenge(c: Challenge): boolean {
  return !!c && typeof c.id === 'string' && c.id.length === 43 && kinds.has(c.type)
    && Number.isFinite(c.expiresInMs) && c.expiresInMs > 0 && c.expiresInMs <= 900_000
    && Number.isFinite(c.minimumDelayMs) && c.minimumDelayMs >= 0 && c.minimumDelayMs <= 10_000
    && (!c.work || (typeof c.work.seed === 'string' && c.work.seed.length === 43 && Number.isInteger(c.work.bits) && c.work.bits >= 1 && c.work.bits <= 20))
    && (!c.image || /^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(c.image))
    && (!c.piece || /^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(c.piece))
    && (!c.audio || /^data:audio\/wav;base64,[A-Za-z0-9+/=]+$/.test(c.audio));
}
function csrfHeader(target: URL, explicitToken: string): Record<string, string> {
  if (typeof location === 'undefined' || target.origin !== location.origin) return {};
  const direct = explicitToken || (typeof document === 'undefined' ? '' : document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '');
  if (direct) return { 'X-CSRF-TOKEN': direct };
  if (typeof document === 'undefined') return {};
  const encoded = document.cookie.split(';').map(part => part.trim()).find(part => part.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
  if (!encoded) return {};
  try { return { 'X-XSRF-TOKEN': decodeURIComponent(encoded) }; } catch { return {}; }
}
/** Browser-only public client. No site secret is accepted or stored here. */
export class CaptchaClient {
  private readonly base: URL;
  constructor(endpoint = '/', private readonly transport: typeof fetch = globalThis.fetch, private readonly csrfToken = '') {
    const fallback = typeof location === 'undefined' ? 'http://localhost/' : location.href;
    this.base = new URL(endpoint.endsWith('/') ? endpoint : endpoint + '/', fallback);
    if (!['http:', 'https:'].includes(this.base.protocol) || this.base.username || this.base.password || this.base.search || this.base.hash) throw new CaptchaError('invalid_endpoint');
  }
  private async post<T>(path: string, body: unknown, signal?: AbortSignal): Promise<T> {
    const controller = new AbortController();
    const abort = () => controller.abort();
    signal?.addEventListener('abort', abort, { once: true });
    if (signal?.aborted) controller.abort();
    const timer = setTimeout(abort, 15_000);
    try {
      const target = new URL(path, this.base);
      const headers = { 'Content-Type': 'application/json', ...csrfHeader(target, this.csrfToken) };
      // `same-origin` lets an embedded Laravel package receive its own session
      // cookie while still refusing browser credentials on cross-origin calls.
      const r = await this.transport.call(globalThis, target, { method: 'POST', credentials: 'same-origin', redirect: 'error', cache: 'no-store', headers, body: JSON.stringify(body), signal: controller.signal });
      const text = await r.text();
      if (text.length > 1_000_000) throw new CaptchaError('invalid_response', r.status);
      let data: T & { code?: string };
      try { data = JSON.parse(text); } catch { throw new CaptchaError('invalid_response', r.status); }
      if (!r.ok) throw new CaptchaError(data?.code ?? 'request_failed', r.status);
      return data;
    } catch (error) {
      if (error instanceof CaptchaError) throw error;
      throw new CaptchaError(controller.signal.aborted ? 'request_cancelled' : 'network_error');
    } finally { clearTimeout(timer); signal?.removeEventListener('abort', abort); }
  }
  async createSession(action: string, variant: SessionVariant = 'standard', signal?: AbortSignal): Promise<CaptchaSession> {
    if (!/^[A-Za-z0-9._-]{1,64}$/.test(action) || !['standard', 'accessible'].includes(variant)) throw new CaptchaError('invalid_context');
    if (typeof location !== 'undefined' && this.base.origin !== location.origin) throw new CaptchaError('managed_session_requires_same_origin');
    const session = await this.post<CaptchaSession>(`session/${encodeURIComponent(action)}`, { variant }, signal);
    if (!session || typeof session.sessionToken !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(session.sessionToken)
      || !Number.isFinite(session.expiresInMs) || session.expiresInMs <= 0 || session.expiresInMs > 900_000
      || typeof session.accessibilityAvailable !== 'boolean') throw new CaptchaError('invalid_response');
    return session;
  }
  async issue(sessionToken: string, signal?: AbortSignal): Promise<Challenge> {
    const challenge = await this.post<Challenge>('v1/challenges', { sessionToken }, signal);
    if (!validChallenge(challenge)) throw new CaptchaError('invalid_response');
    return challenge;
  }
  async solve(sessionToken: string, challengeId: string, answer: Answer, workNonce?: string, signal?: AbortSignal): Promise<Proof> {
    const proof = await this.post<Proof>('v1/answers', { sessionToken, challengeId, answer, workNonce }, signal);
    if (!proof || typeof proof.token !== 'string' || !/^[a-f0-9]{64}\.[A-Za-z0-9_-]{43}$/.test(proof.token) || !Number.isFinite(proof.expiresInMs) || proof.expiresInMs <= 0 || proof.expiresInMs > 120_000) throw new CaptchaError('invalid_response');
    return proof;
  }
}

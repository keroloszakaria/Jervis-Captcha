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
export interface CaptchaSession { sessionToken: string; expiresInMs: number; accessibilityAvailable: boolean; fieldName: string }
export class CaptchaError extends Error {
  constructor(public readonly code: string, public readonly status = 0) { super(code); this.name = 'CaptchaError'; }
}
const tokenPattern = /^[A-Za-z0-9_-]{43}$/;
const fieldPattern = /^[A-Za-z_][A-Za-z0-9_-]{0,63}$/;
const imagePattern = /^data:image\/png;base64,[A-Za-z0-9+/=]+$/;
const audioPattern = /^data:audio\/wav;base64,[A-Za-z0-9+/=]+$/;
const kinds = new Set<ChallengeType>(['text', 'slider', 'drag', 'rotate', 'click', 'audio', 'math', 'logic', 'pow']);
function validInteger(value: unknown, min: number, max: number): value is number {
  return Number.isInteger(value) && Number(value) >= min && Number(value) <= max;
}
function validWork(work: Work | undefined): boolean {
  return work === undefined || (tokenPattern.test(work.seed) && Number.isInteger(work.bits) && work.bits >= 1 && work.bits <= 20);
}
function validGeometry(c: Challenge, pieceRequired: boolean): boolean {
  if (!validInteger(c.width, 80, 1000) || !validInteger(c.height, 60, 1000)) return false;
  if (!pieceRequired) return true;
  if (!validInteger(c.pieceSize, 8, Math.min(c.width!, c.height!))) return false;
  return validInteger(c.pieceTop, 0, c.height! - c.pieceSize!);
}
function validChallenge(c: Challenge): boolean {
  if (!c || typeof c.id !== 'string' || !tokenPattern.test(c.id) || !kinds.has(c.type)
    || typeof c.prompt !== 'string' || c.prompt.length === 0 || c.prompt.length > 500
    || !Number.isFinite(c.expiresInMs) || c.expiresInMs <= 0 || c.expiresInMs > 900_000
    || !Number.isFinite(c.minimumDelayMs) || c.minimumDelayMs < 0 || c.minimumDelayMs > 10_000
    || !validWork(c.work)
    || (c.image !== undefined && !imagePattern.test(c.image))
    || (c.piece !== undefined && !imagePattern.test(c.piece))
    || (c.audio !== undefined && !audioPattern.test(c.audio))) return false;

  switch (c.type) {
    case 'text':
    case 'rotate':
      return typeof c.image === 'string' && imagePattern.test(c.image) && c.piece === undefined && c.audio === undefined;
    case 'slider':
    case 'drag':
      return typeof c.image === 'string' && imagePattern.test(c.image)
        && typeof c.piece === 'string' && imagePattern.test(c.piece)
        && c.audio === undefined && validGeometry(c, true);
    case 'click':
      return typeof c.image === 'string' && imagePattern.test(c.image)
        && c.piece === undefined && c.audio === undefined && validGeometry(c, false);
    case 'audio':
      return typeof c.audio === 'string' && audioPattern.test(c.audio) && c.image === undefined && c.piece === undefined;
    case 'math':
    case 'logic':
      return c.image === undefined && c.piece === undefined && c.audio === undefined;
    case 'pow':
      return c.image === undefined && c.piece === undefined && c.audio === undefined && c.work !== undefined;
  }
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
      const r = await this.transport.call(globalThis, target, { method: 'POST', credentials: 'same-origin', redirect: 'error', cache: 'no-store', headers, body: JSON.stringify(body), signal: controller.signal });
      const contentLength = Number(r.headers.get('content-length') ?? '0');
      if (Number.isFinite(contentLength) && contentLength > 1_000_000) throw new CaptchaError('invalid_response', r.status);
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
    if (!session || typeof session.sessionToken !== 'string' || !tokenPattern.test(session.sessionToken)
      || !Number.isFinite(session.expiresInMs) || session.expiresInMs <= 0 || session.expiresInMs > 900_000
      || typeof session.accessibilityAvailable !== 'boolean' || typeof session.fieldName !== 'string' || !fieldPattern.test(session.fieldName)) {
      throw new CaptchaError('invalid_response');
    }
    return session;
  }
  async issue(sessionToken: string, signal?: AbortSignal): Promise<Challenge> {
    if (!tokenPattern.test(sessionToken)) throw new CaptchaError('invalid_session');
    const challenge = await this.post<Challenge>('v1/challenges', { sessionToken }, signal);
    if (!validChallenge(challenge)) throw new CaptchaError('invalid_response');
    return challenge;
  }
  async solve(sessionToken: string, challengeId: string, answer: Answer, workNonce?: string, signal?: AbortSignal): Promise<Proof> {
    if (!tokenPattern.test(sessionToken) || !tokenPattern.test(challengeId)) throw new CaptchaError('invalid_challenge');
    const proof = await this.post<Proof>('v1/answers', { sessionToken, challengeId, answer, workNonce }, signal);
    if (!proof || typeof proof.token !== 'string' || !/^[a-f0-9]{64}\.[A-Za-z0-9_-]{43}$/.test(proof.token) || !Number.isFinite(proof.expiresInMs) || proof.expiresInMs <= 0 || proof.expiresInMs > 120_000) throw new CaptchaError('invalid_response');
    return proof;
  }
}

import {
  CaptchaClient as BaseCaptchaClient,
  CaptchaError,
  JervisCaptcha,
  registerCaptcha,
} from './jervis-captcha-base.js';

const ACTION = /^[A-Za-z0-9._-]{1,64}$/;
const csrfByOrigin = new Map();
const managed = new WeakMap();

function csrfCookie() {
  if (typeof document === 'undefined') return '';
  const raw = document.cookie.split(';').map((part) => part.trim()).find((part) => part.startsWith('XSRF-TOKEN='));
  if (!raw) return '';
  try { return decodeURIComponent(raw.slice('XSRF-TOKEN='.length)); } catch { return ''; }
}

function csrfFor(target, explicit = '') {
  if (typeof location === 'undefined' || target.origin !== location.origin) return {};
  const direct = explicit || csrfByOrigin.get(target.origin) || document.querySelector('meta[name="csrf-token"]')?.content || '';
  if (direct) return { 'X-CSRF-TOKEN': direct };
  const cookie = csrfCookie();
  return cookie ? { 'X-XSRF-TOKEN': cookie } : {};
}

BaseCaptchaClient.prototype.post = async function (path, body, signal) {
  const controller = new AbortController();
  const abort = () => controller.abort();
  signal?.addEventListener('abort', abort, { once: true });
  if (signal?.aborted) controller.abort();
  const timer = setTimeout(abort, 15000);
  try {
    const target = new URL(path, this.base);
    const response = await this.transport.call(globalThis, target, {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'error',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', ...csrfFor(target, this.csrfToken || '') },
      body: JSON.stringify(body),
      signal: controller.signal,
    });
    const text = await response.text();
    if (text.length > 1000000) throw new CaptchaError('invalid_response', response.status);
    let data;
    try { data = JSON.parse(text); } catch { throw new CaptchaError('invalid_response', response.status); }
    if (!response.ok) throw new CaptchaError(data?.code || 'request_failed', response.status);
    return data;
  } catch (error) {
    if (error instanceof CaptchaError) throw error;
    throw new CaptchaError(controller.signal.aborted ? 'request_cancelled' : 'network_error');
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener('abort', abort);
  }
};

BaseCaptchaClient.prototype.createSession = async function (action, signal) {
  if (!ACTION.test(action)) throw new CaptchaError('invalid_context');
  if (typeof location !== 'undefined' && this.base.origin !== location.origin) throw new CaptchaError('managed_session_requires_same_origin');
  const session = await this.post(`session/${encodeURIComponent(action)}`, {}, signal);
  if (!session || typeof session.sessionToken !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(session.sessionToken)
    || !Number.isFinite(session.expiresInMs) || session.expiresInMs <= 0 || session.expiresInMs > 900000) {
    throw new CaptchaError('invalid_response');
  }
  return session;
};

export class CaptchaClient extends BaseCaptchaClient {
  constructor(endpoint = '/', transport = globalThis.fetch, csrfToken = '') {
    super(endpoint, transport);
    this.csrfToken = csrfToken;
    if (typeof location !== 'undefined' && this.base.origin === location.origin && csrfToken) csrfByOrigin.set(this.base.origin, csrfToken);
  }
}

function elementEndpoint(element) {
  return element.getAttribute('endpoint') || element.endpoint || '/';
}

function elementCsrf(element) {
  return element.getAttribute('csrf-token') || element.csrfToken || '';
}

function reportManagedError(element, error) {
  const code = error instanceof CaptchaError ? error.code : 'error';
  element.dispatchEvent(new CustomEvent('jervis-error', { detail: { code }, bubbles: true, composed: true }));
}

async function bootstrap(element, force = false) {
  if (!(element instanceof JervisCaptcha) || !element.isConnected) return;
  const action = element.getAttribute('action') || element.action || '';
  if (!action) return;
  if (!ACTION.test(action)) { reportManagedError(element, new CaptchaError('invalid_context')); return; }

  let base;
  try { base = new URL(elementEndpoint(element), location.href); } catch { reportManagedError(element, new CaptchaError('invalid_endpoint')); return; }
  if (base.origin !== location.origin) { reportManagedError(element, new CaptchaError('managed_session_requires_same_origin')); return; }

  const csrf = elementCsrf(element);
  if (csrf) csrfByOrigin.set(base.origin, csrf);
  const previous = managed.get(element);
  if (!force && previous?.token && element.sessionToken === previous.token) return;
  previous?.controller?.abort();
  const controller = new AbortController();
  const generation = (previous?.generation || 0) + 1;
  const state = { generation, controller, token: '' };
  managed.set(element, state);

  try {
    const session = await new CaptchaClient(base.href, globalThis.fetch, csrf).createSession(action, controller.signal);
    if (!element.isConnected || managed.get(element)?.generation !== generation) return;
    state.token = session.sessionToken;
    element.sessionToken = session.sessionToken;
  } catch (error) {
    if (!controller.signal.aborted && managed.get(element)?.generation === generation) reportManagedError(element, error);
  }
}

function setup(element) {
  if (!(element instanceof JervisCaptcha) || element.dataset.jervisManaged === '1') return;
  element.dataset.jervisManaged = '1';
  element.addEventListener('jervis-expired', () => void bootstrap(element, true));
  element.addEventListener('jervis-renew', () => void bootstrap(element, true));
  element.addEventListener('jervis-error', (event) => {
    const code = event.detail?.code;
    if (['session_locked', 'session_expired', 'challenge_expired', 'invalid_session'].includes(code)) void bootstrap(element, true);
  });
  void bootstrap(element);
}

if (!Object.getOwnPropertyDescriptor(JervisCaptcha.prototype, 'action')) {
  Object.defineProperty(JervisCaptcha.prototype, 'action', {
    get() { return this.getAttribute('action') || ''; },
    set(value) { value ? this.setAttribute('action', String(value)) : this.removeAttribute('action'); },
  });
}
if (!Object.getOwnPropertyDescriptor(JervisCaptcha.prototype, 'csrfToken')) {
  Object.defineProperty(JervisCaptcha.prototype, 'csrfToken', {
    get() { return this.getAttribute('csrf-token') || ''; },
    set(value) { value ? this.setAttribute('csrf-token', String(value)) : this.removeAttribute('csrf-token'); },
  });
}
JervisCaptcha.prototype.renew = function () { return bootstrap(this, true); };

function scan(root = document) {
  root.querySelectorAll?.('jervis-captcha[action]').forEach(setup);
  if (root instanceof Element && root.matches('jervis-captcha[action]')) setup(root);
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => scan(), { once: true });
  else scan();
  new MutationObserver((records) => {
    for (const record of records) {
      if (record.type === 'childList') record.addedNodes.forEach((node) => node instanceof Element && scan(node));
      else if (record.target instanceof JervisCaptcha) void bootstrap(record.target, true);
    }
  }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['action', 'endpoint', 'csrf-token'] });
}

registerCaptcha();
export { CaptchaError, JervisCaptcha, registerCaptcha };

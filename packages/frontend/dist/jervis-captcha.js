import {
  CaptchaClient as BaseCaptchaClient,
  CaptchaError,
  JervisCaptcha,
  registerCaptcha,
} from './jervis-captcha-base.js';

const ACTION = /^[A-Za-z0-9._-]{1,64}$/;
const VARIANTS = new Set(['standard', 'accessible']);
const csrfByOrigin = new Map();
const managed = new WeakMap();
const shadowObservers = new WeakMap();

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

BaseCaptchaClient.prototype.createSession = async function (action, variant = 'standard', signal) {
  if (!ACTION.test(action) || !VARIANTS.has(variant)) throw new CaptchaError('invalid_context');
  if (typeof location !== 'undefined' && this.base.origin !== location.origin) throw new CaptchaError('managed_session_requires_same_origin');
  const session = await this.post(`session/${encodeURIComponent(action)}`, { variant }, signal);
  if (!session || typeof session.sessionToken !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(session.sessionToken)
    || !Number.isFinite(session.expiresInMs) || session.expiresInMs <= 0 || session.expiresInMs > 900000
    || typeof session.accessibilityAvailable !== 'boolean') {
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
  const explicit = element.getAttribute('endpoint');
  if (explicit) return explicit;
  const action = element.getAttribute('action') || element.action || '';
  return action ? '/jervis-captcha/' : (element.endpoint || '/');
}

function elementCsrf(element) {
  return element.getAttribute('csrf-token') || element.csrfToken || '';
}

function reportManagedError(element, error) {
  const code = error instanceof CaptchaError ? error.code : 'error';
  element.dispatchEvent(new CustomEvent('jervis-error', { detail: { code }, bubbles: true, composed: true }));
}

function accessibilityLabel(element) {
  return element.locale === 'ar' ? 'استخدم تحديًا ميسّرًا' : 'Use an accessible challenge';
}

function ensureAccessibilityControl(element) {
  const root = element.shadowRoot;
  if (!root) return;
  const state = managed.get(element);
  const existing = root.querySelector('[data-jervis-accessible-fallback]');
  const eligible = !!state?.accessibilityAvailable && state.variant === 'standard'
    && element.status === 'ready' && element.challenge?.type !== 'audio';
  if (!eligible) {
    existing?.remove();
    return;
  }
  if (existing) {
    const label = accessibilityLabel(element);
    if (existing.textContent !== label) existing.textContent = label;
    return;
  }
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'refresh accessible';
  button.dataset.jervisAccessibleFallback = '1';
  button.textContent = accessibilityLabel(element);
  button.style.cssText = 'width:100%;margin-top:10px';
  button.addEventListener('click', () => void bootstrap(element, true, 'accessible'));
  const status = root.querySelector('.status');
  if (status) status.before(button); else root.append(button);
}

async function watchShadow(element) {
  try { await element.updateComplete; } catch { return; }
  const root = element.shadowRoot;
  if (!root || shadowObservers.has(element)) return;
  const observer = new MutationObserver(() => ensureAccessibilityControl(element));
  observer.observe(root, { childList: true, subtree: true });
  shadowObservers.set(element, observer);
  ensureAccessibilityControl(element);
}

async function bootstrap(element, force = false, requestedVariant) {
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
  const variant = requestedVariant || previous?.variant || 'standard';
  if (!VARIANTS.has(variant)) { reportManagedError(element, new CaptchaError('invalid_context')); return; }
  if (!force && previous?.token && element.sessionToken === previous.token) return;
  previous?.controller?.abort();
  const controller = new AbortController();
  const generation = (previous?.generation || 0) + 1;
  const state = { generation, controller, token: '', variant, accessibilityAvailable: false };
  managed.set(element, state);
  ensureAccessibilityControl(element);

  try {
    const session = await new CaptchaClient(base.href, globalThis.fetch, csrf).createSession(action, variant, controller.signal);
    if (!element.isConnected || managed.get(element)?.generation !== generation) return;
    state.token = session.sessionToken;
    state.accessibilityAvailable = session.accessibilityAvailable;
    element.sessionToken = session.sessionToken;
    void watchShadow(element);
  } catch (error) {
    if (!controller.signal.aborted && managed.get(element)?.generation === generation) reportManagedError(element, error);
  }
}

function setup(element) {
  if (!(element instanceof JervisCaptcha) || element.dataset.jervisManaged === '1') return;
  element.dataset.jervisManaged = '1';
  element.addEventListener('jervis-expired', () => void bootstrap(element, true));
  element.addEventListener('jervis-renew', () => void bootstrap(element, true, 'standard'));
  element.addEventListener('jervis-error', (event) => {
    const code = event.detail?.code;
    if (['session_locked', 'session_expired', 'challenge_expired', 'invalid_session'].includes(code)) void bootstrap(element, true);
  });
  void watchShadow(element);
  void bootstrap(element, false, 'standard');
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
JervisCaptcha.prototype.renew = function () { return bootstrap(this, true, managed.get(this)?.variant || 'standard'); };

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
      else if (record.target instanceof JervisCaptcha) void bootstrap(record.target, true, 'standard');
    }
  }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['action', 'endpoint', 'csrf-token'] });
}

registerCaptcha();
export { CaptchaError, JervisCaptcha, registerCaptcha };
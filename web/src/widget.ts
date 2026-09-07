import { LitElement, html, nothing } from 'lit';
import type { PropertyValues } from 'lit';
import { CaptchaClient, CaptchaError } from './client';
import type { Answer, Challenge, Point, Proof, Work } from './client';
import { messages } from './i18n';
import type { MessageKey } from './i18n';
import { styles } from './styles';
import ProofWorker from './pow.worker?worker&inline';

type Status = 'idle' | 'loading' | 'ready' | 'verifying' | 'success' | 'expired' | 'error';
/** Framework-neutral, form-associated widget. Trust decisions remain on the server. */
export class JervisCaptcha extends LitElement {
  static formAssociated = true;
  static styles = styles;
  static properties = {
    endpoint: { type: String }, sessionToken: { attribute: false },
    presentation: { type: String }, locale: { type: String }, theme: { type: String, reflect: true },
    disabled: { type: Boolean, reflect: true }, name: { type: String, reflect: true },
    status: { state: true }, challenge: { state: true }, errorCode: { state: true },
    answerText: { state: true }, x: { state: true }, y: { state: true }, angle: { state: true },
    points: { state: true }, cursor: { state: true }, working: { state: true },
  };
  endpoint = '/'; sessionToken = ''; presentation: 'inline' | 'checkbox' | 'invisible' = 'inline';
  locale: 'en' | 'ar' = 'en'; theme: 'light' | 'dark' | 'auto' = 'auto'; disabled = false; name = 'jervisCaptchaToken';
  private status: Status = 'idle'; private challenge?: Challenge; private errorCode = '';
  private answerText = ''; private x = 0; private y = 0; private angle = 0;
  private points: Point[] = []; private cursor?: Point; private working = false;
  private proof = ''; private lastType = ''; private operation = 0; private readyAt = 0;
  private expiry?: ReturnType<typeof setTimeout>; private controller?: AbortController;
  private worker?: Worker; private workNonce?: string; private internals?: ElementInternals;
  constructor() { super(); if (typeof this.attachInternals === 'function') this.internals = this.attachInternals(); }
  get token(): string { return this.status === 'success' ? this.proof : ''; }
  private get t() { return messages[this.locale === 'ar' ? 'ar' : 'en']; }
  private event(name: string, detail?: unknown) { this.dispatchEvent(new CustomEvent(name, { detail, bubbles: true, composed: true })); }
  private invalidate() {
    this.operation++; this.controller?.abort(); this.controller = undefined;
    this.worker?.terminate(); this.worker = undefined; this.working = false; this.workNonce = undefined;
    clearTimeout(this.expiry); this.proof = ''; this.internals?.setFormValue(null);
    this.internals?.setValidity({ customError: true }, this.t.required);
    this.event('jervis-reset');
  }
  reset() { this.invalidate(); this.challenge = undefined; this.status = 'idle'; this.errorCode = ''; }
  formResetCallback() { this.reset(); this.event('jervis-renew'); }
  formDisabledCallback(disabled: boolean) { this.disabled = disabled; }
  formStateRestoreCallback() { this.reset(); this.event('jervis-renew'); }
  override disconnectedCallback() { this.reset(); super.disconnectedCallback(); }
  override connectedCallback() { super.connectedCallback(); if (this.hasUpdated && this.sessionToken && this.presentation !== 'checkbox') void this.start(); }
  protected override updated(changed: PropertyValues<this>) {
    if (changed.has('sessionToken') || changed.has('endpoint')) {
      this.reset();
      if (this.sessionToken && this.presentation !== 'checkbox' && !this.disabled) void this.start();
    }
    if (changed.has('disabled') && this.disabled) this.reset();
  }
  private setExpiry(milliseconds: number, operation: number) {
    clearTimeout(this.expiry);
    this.expiry = setTimeout(() => {
      if (operation !== this.operation) return;
      this.invalidate(); this.status = 'expired'; this.event('jervis-expired');
    }, Math.max(0, milliseconds));
  }
  async start(): Promise<void> {
    if (!this.sessionToken || this.disabled || !this.isConnected) return;
    this.invalidate(); const op = this.operation; this.controller = new AbortController();
    this.challenge = undefined; this.status = 'loading'; this.errorCode = '';
    this.answerText = ''; this.x = 0; this.y = 0; this.angle = 0; this.points = []; this.cursor = undefined;
    const begin = performance.now();
    try {
      const c = await new CaptchaClient(this.endpoint).issue(this.sessionToken, this.controller.signal);
      if (op !== this.operation) return;
      this.challenge = c; this.lastType = c.type; this.y = c.pieceTop ?? 0; this.status = 'ready';
      this.readyAt = performance.now() + c.minimumDelayMs;
      this.setExpiry(c.expiresInMs - (performance.now() - begin), op);
      if (c.work) {
        this.working = true;
        this.workNonce = await this.computeWork(c.work, this.controller.signal);
        if (op !== this.operation) return;
        this.working = false;
      }
      if (c.type === 'pow' && op === this.operation) await this.verify();
    } catch (error) { if (op === this.operation) this.showError(error); }
  }
  private computeWork(work: Work, signal: AbortSignal): Promise<string> {
    return new Promise((resolve, reject) => {
      const worker = new ProofWorker(); this.worker = worker;
      let timer: ReturnType<typeof setTimeout>;
      const finish = (value?: string, error?: string) => {
        clearTimeout(timer); signal.removeEventListener('abort', abort); worker.terminate();
        if (this.worker === worker) this.worker = undefined;
        if (error) reject(new CaptchaError(error)); else resolve(value!);
      };
      const abort = () => finish(undefined, 'request_cancelled');
      signal.addEventListener('abort', abort, { once: true });
      if (signal.aborted) { abort(); return; }
      timer = setTimeout(() => finish(undefined, 'work_timeout'), 6500);
      worker.onerror = () => finish(undefined, 'work_unavailable');
      worker.onmessage = (event: MessageEvent<{ nonce?: string; error?: string }>) => {
        if (event.data.error || !/^\d{1,8}$/.test(event.data.nonce ?? '')) finish(undefined, event.data.error ?? 'invalid_work');
        else finish(event.data.nonce);
      };
      worker.postMessage(work);
    });
  }
  private showError(error: unknown) {
    this.working = false;
    this.errorCode = error instanceof CaptchaError ? error.code : 'error';
    this.status = ['incorrect_answer', 'too_early'].includes(this.errorCode) && this.challenge ? 'ready' : 'error';
    this.event('jervis-error', { code: this.errorCode });
  }
  private answer(): Answer {
    const type = this.challenge?.type;
    if (type === 'slider') return { x: this.x };
    if (type === 'drag') return { x: this.x, y: this.y };
    if (type === 'rotate') return { angle: this.angle };
    if (type === 'click') return { points: this.points };
    return { text: this.answerText };
  }
  async verify(): Promise<void> {
    const c = this.challenge;
    if (!c || this.status !== 'ready' || this.disabled || this.working) return;
    const op = this.operation; this.status = 'verifying'; this.errorCode = '';
    try {
      const remaining = this.readyAt - performance.now();
      if (remaining > 0) await new Promise(resolve => setTimeout(resolve, remaining));
      if (op !== this.operation) return;
      const begin = performance.now();
      const proof: Proof = await new CaptchaClient(this.endpoint).solve(this.sessionToken, c.id, this.answer(), this.workNonce, this.controller?.signal);
      if (op !== this.operation) return;
      const ttl = proof.expiresInMs - (performance.now() - begin);
      if (ttl <= 0) { this.invalidate(); this.status = 'expired'; this.event('jervis-expired'); return; }
      this.proof = proof.token; this.status = 'success'; this.challenge = undefined;
      this.internals?.setValidity({}); this.internals?.setFormValue(proof.token);
      this.setExpiry(ttl, op); this.event('jervis-success', { ...proof, expiresInMs: ttl });
    } catch (error) { if (op === this.operation) this.showError(error); }
  }
  private move(event: PointerEvent) {
    const c = this.challenge;
    if (!c || this.status !== 'ready' || this.disabled) return;
    const board = event.currentTarget as HTMLElement, rect = board.getBoundingClientRect();
    const x = (event.clientX - rect.left) * (c.width ?? 300) / rect.width;
    const y = (event.clientY - rect.top) * (c.height ?? 160) / rect.height;
    if (c.type === 'click') { if (event.type === 'pointerdown' && this.points.length < 3) this.points = [...this.points, { x, y }]; return; }
    if (!['slider', 'drag'].includes(c.type)) return;
    if (event.type === 'pointerdown') board.setPointerCapture(event.pointerId);
    if (event.type !== 'pointerdown' && !board.hasPointerCapture(event.pointerId)) return;
    this.x = Math.round(Math.max(0, Math.min((c.width ?? 300) - (c.pieceSize ?? 44), x - (c.pieceSize ?? 44) / 2)));
    if (c.type === 'drag') this.y = Math.round(Math.max(0, Math.min((c.height ?? 160) - (c.pieceSize ?? 44), y - (c.pieceSize ?? 44) / 2)));
  }
  private keyboard(event: KeyboardEvent) {
    if (this.challenge?.type !== 'click' || this.disabled || this.status !== 'ready') return;
    const p = this.cursor ?? { x: 150, y: 80 }; const delta = event.shiftKey ? 1 : 10;
    if (event.key === 'ArrowLeft') p.x -= delta; else if (event.key === 'ArrowRight') p.x += delta;
    else if (event.key === 'ArrowUp') p.y -= delta; else if (event.key === 'ArrowDown') p.y += delta;
    else if (event.key === 'Enter' && this.points.length < 3) this.points = [...this.points, { ...p }];
    else return;
    event.preventDefault(); this.cursor = { x: Math.max(0, Math.min(300, p.x)), y: Math.max(0, Math.min(160, p.y)) };
  }
  private range(key: 'x' | 'y' | 'angle', title: string, max: number) {
    return html`<label>${title}<output>${this[key]}</output><input type="range" min="0" max=${max} step="1" .value=${String(this[key])} aria-label=${title} ?disabled=${this.disabled || this.status !== 'ready'} @input=${(e: Event) => { this[key] = Number((e.target as HTMLInputElement).value); }}/></label>`;
  }
  private challengeView() {
    const c = this.challenge; if (!c) return nothing;
    const picture = c.image && ['slider', 'drag', 'click'].includes(c.type);
    const prompt = this.t[c.type as MessageKey] ?? c.prompt;
    return html`
      <p class="prompt" id="instruction">${prompt}</p>
      ${picture ? html`<div class="board" role="group" aria-label=${prompt} tabindex=${c.type === 'click' ? '0' : '-1'} @keydown=${this.keyboard} @pointerdown=${this.move} @pointermove=${this.move} @pointerup=${(e: PointerEvent) => { const el = e.currentTarget as HTMLElement; if (el.hasPointerCapture(e.pointerId)) el.releasePointerCapture(e.pointerId); }}>
        <img src=${c.image!} alt=${prompt} draggable="false"/>
        ${c.piece ? html`<img class="piece" src=${c.piece} alt="" draggable="false" style=${`width:${(c.pieceSize ?? 44) / (c.width ?? 300) * 100}%;left:${this.x / (c.width ?? 300) * 100}%;top:${this.y / (c.height ?? 160) * 100}%`}/>` : nothing}
        ${this.points.map((p, i) => html`<span class="point" style=${`left:${p.x / 3}%;top:${p.y / 1.6}%`}>${i + 1}</span>`)}
        ${this.cursor ? html`<span class="cursor" style=${`left:${this.cursor.x / 3}%;top:${this.cursor.y / 1.6}%`}></span>` : nothing}
      </div>` : nothing}
      ${c.type === 'text' ? html`<img class="text-image" src=${c.image!} alt=${this.t.text}/>` : nothing}
      ${c.type === 'rotate' ? html`<div class="rotation"><img src=${c.image!} alt=${this.t.rotate} style=${`transform:rotate(${this.angle}deg)`}/></div>${this.range('angle', this.t.angle, 359)}` : nothing}
      ${['slider', 'drag'].includes(c.type) ? this.range('x', this.t.horizontal, (c.width ?? 300) - (c.pieceSize ?? 44)) : nothing}
      ${c.type === 'drag' ? this.range('y', this.t.vertical, (c.height ?? 160) - (c.pieceSize ?? 44)) : nothing}
      ${c.type === 'click' ? html`<button type="button" @click=${() => { this.points = []; }} ?disabled=${this.disabled || this.status !== 'ready'}>${this.t.clear} (${this.points.length}/3)</button>` : nothing}
      ${['math', 'logic'].includes(c.type) ? html`<div class="math">${c.prompt}</div>` : nothing}
      ${c.audio ? html`<audio controls preload="none" src=${c.audio} aria-label=${this.t.audio}></audio>` : nothing}
      ${['text', 'math', 'logic', 'audio'].includes(c.type) ? html`<label>${this.t.answer}<input class="answer-input" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="64" dir="ltr" aria-describedby="instruction" .value=${this.answerText} ?disabled=${this.disabled || this.status !== 'ready'} @input=${(e: Event) => { this.answerText = (e.target as HTMLInputElement).value; }} @keydown=${(e: KeyboardEvent) => { if (e.key === 'Enter') { e.preventDefault(); void this.verify(); } }}/></label>` : nothing}
    `;
  }
  override render() {
    const hidden = this.presentation === 'invisible' && (this.challenge?.type === 'pow' || (this.status === 'success' && this.lastType === 'pow')) && this.status !== 'error';
    const errorMessage = this.errorCode ? this.t[this.errorCode as MessageKey] ?? this.t.error : '';
    return html`<section part="container" class=${hidden ? 'card hidden' : 'card'} dir=${this.locale === 'ar' ? 'rtl' : 'ltr'} aria-busy=${this.status === 'loading' || this.status === 'verifying'}>
      <header><span class="mark" aria-hidden="true">J</span><div><h2>${this.t.title}</h2><small>${this.t.subtitle}</small></div></header>
      ${this.status === 'success' ? html`<div class="success" role="status">✓ ${this.t.success}</div>` : this.challengeView()}
      ${this.status === 'idle' && this.sessionToken ? html`<button class="primary" type="button" role=${this.presentation === 'checkbox' ? 'checkbox' : 'button'} aria-checked=${this.presentation === 'checkbox' ? 'false' : nothing} @click=${() => this.start()} ?disabled=${this.disabled}>${this.t.start}</button>` : nothing}
      ${this.challenge && this.status === 'ready' ? html`<div class="actions"><button class="primary" part="verify-button" type="button" @click=${() => this.verify()} ?disabled=${this.disabled || this.working || this.challenge.type === 'pow'}>${this.t.verify}</button><button class="refresh" type="button" @click=${() => this.start()} ?disabled=${this.disabled}>${this.t.refresh}</button></div>` : nothing}
      <div class=${errorMessage ? 'status error' : 'status'} role="status" aria-live="polite">${this.working ? html`<span class="spinner"></span>${this.t.work}` : errorMessage || (this.status === 'success' ? '' : this.t[this.status as MessageKey] ?? '')}</div>
      <footer><span>Jervis Captcha</span><span>${this.t.local}</span></footer>
    </section>`;
  }
}

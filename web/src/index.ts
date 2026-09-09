export { CaptchaClient, CaptchaError } from './client';
export type { Challenge, ChallengeType, Point, Answer, Proof, Work, CaptchaSession, SessionVariant } from './client';
export { JervisCaptcha } from './widget';
import { JervisCaptcha } from './widget';
/** Safe to import during SSR; registration only occurs in a browser. */
export function registerCaptcha() {
  if (typeof customElements !== 'undefined' && !customElements.get('jervis-captcha')) customElements.define('jervis-captcha', JervisCaptcha);
}
registerCaptcha();
declare global { interface HTMLElementTagNameMap { 'jervis-captcha': JervisCaptcha } }

export type ChallengeType = 'text' | 'slider' | 'drag' | 'rotate' | 'click' | 'audio' | 'math' | 'logic' | 'pow';
export interface JervisCaptchaElement extends HTMLElement {
  endpoint: string;
  action: string;
  csrfToken: string;
  sessionToken: string;
  presentation: 'inline' | 'checkbox' | 'invisible';
  locale: 'en' | 'ar';
  theme: 'light' | 'dark' | 'auto';
  disabled: boolean;
  name: string;
  readonly token: string;
  start(): Promise<void>;
  verify(): Promise<void>;
  reset(): void;
  renew(): Promise<void>;
}
export declare function registerCaptcha(): void;
declare global { interface HTMLElementTagNameMap { 'jervis-captcha': JervisCaptchaElement } }

export type ChallengeType = 'text' | 'slider' | 'drag' | 'rotate' | 'click' | 'audio' | 'math' | 'logic' | 'pow';
export type SessionVariant = 'standard' | 'accessible';
export interface Point { x: number; y: number }
export interface Answer { text?: string; x?: number; y?: number; angle?: number; points?: Point[] }
export interface Work { seed: string; bits: number }
export interface Challenge {
  id: string;
  type: ChallengeType;
  prompt: string;
  image?: string;
  piece?: string;
  audio?: string;
  width?: number;
  height?: number;
  pieceSize?: number;
  pieceTop?: number;
  expiresInMs: number;
  minimumDelayMs: number;
  work?: Work;
}
export interface Proof { token: string; expiresInMs: number }
export interface CaptchaSession { sessionToken: string; expiresInMs: number; accessibilityAvailable: boolean }

export declare class CaptchaError extends Error {
  readonly code: string;
  readonly status: number;
  constructor(code: string, status?: number);
}

export declare class CaptchaClient {
  constructor(endpoint?: string, transport?: typeof fetch, csrfToken?: string);
  createSession(action: string, variant?: SessionVariant, signal?: AbortSignal): Promise<CaptchaSession>;
  issue(sessionToken: string, signal?: AbortSignal): Promise<Challenge>;
  solve(sessionToken: string, challengeId: string, answer: Answer, workNonce?: string, signal?: AbortSignal): Promise<Proof>;
}

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

/** Public declaration intentionally exposes only the stable HTMLElement surface. */
export declare class JervisCaptcha extends HTMLElement implements JervisCaptchaElement {
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

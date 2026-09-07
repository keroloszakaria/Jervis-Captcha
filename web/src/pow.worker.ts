import { workMatches } from './sha256';
self.onmessage = (event: MessageEvent<{ seed: string; bits: number }>) => {
  const { seed, bits } = event.data;
  if (!/^[A-Za-z0-9_-]{43}$/.test(seed) || !Number.isInteger(bits) || bits < 1 || bits > 20) { self.postMessage({ error: 'invalid_work' }); return; }
  const start = performance.now();
  for (let i = 0; i < 16_777_216; i++) {
    if (workMatches(seed, String(i), bits)) { self.postMessage({ nonce: String(i) }); return; }
    if ((i & 1023) === 0 && performance.now() - start > 5000) break;
  }
  self.postMessage({ error: 'work_timeout' });
};

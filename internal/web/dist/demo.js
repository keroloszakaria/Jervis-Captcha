import './jervis-captcha.js';
const widget = document.querySelector('#captcha');
const submit = document.querySelector('#submit');
const result = document.querySelector('#result');
const events = document.querySelector('#events');
let token = '', generation = 0;
function log(message) { events.textContent = `${new Date().toLocaleTimeString()}  ${message}\n` + events.textContent.slice(0, 1500); }
async function request(path, body) {
  const response = await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) });
  const data = await response.json(); if (!response.ok) throw new Error(data.code ?? 'request_failed'); return data;
}
async function renew() {
  const current = ++generation; widget.sessionToken = ''; token = ''; submit.disabled = true;
  result.textContent = 'Preparing a host-bound verification session…';
  try { const session = await request('/demo/session', { type: document.querySelector('#kind').value }); if (current !== generation) return; widget.sessionToken = session.sessionToken; result.textContent = 'Complete verification, then submit the protected action.'; log('Host backend created a session.'); }
  catch (e) { if (current === generation) result.textContent = `Local service error: ${e.message}`; }
}
widget.addEventListener('jervis-success', event => { token = event.detail.token; submit.disabled = false; log('Challenge solved; short-lived proof received (redacted).'); });
widget.addEventListener('jervis-reset', () => { token = ''; submit.disabled = true; });
widget.addEventListener('jervis-expired', () => { token = ''; submit.disabled = true; log('Verification expired.'); });
widget.addEventListener('jervis-error', event => log(`Widget error: ${event.detail.code}`));
widget.addEventListener('jervis-renew', renew);
document.querySelector('#kind').addEventListener('change', renew);
document.querySelector('#locale').addEventListener('change', e => { widget.locale = e.target.value; });
document.querySelector('#theme').addEventListener('change', e => { widget.theme = e.target.value; });
document.querySelector('#presentation').addEventListener('change', e => { widget.presentation = e.target.value; renew(); });
document.querySelector('#renew').addEventListener('click', renew);
submit.addEventListener('click', async () => {
  if (!token) return; submit.disabled = true; const proof = token; token = '';
  try { await request('/demo/submit', { token: proof }); result.textContent = 'Protected action accepted. The proof has been consumed and cannot be reused.'; log('Host backend: verification consumed exactly once.'); }
  catch (e) { result.textContent = `Protected action rejected: ${e.message}`; log('Host backend rejected the action.'); }
});
renew();

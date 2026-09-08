import { JervisCaptchaServer } from '../../.sdk-build/client.js';
let raw = '';
for await (const chunk of process.stdin) raw += chunk;
try {
  const input = JSON.parse(raw);
  const client = new JervisCaptchaServer(input);
  const result = input.operation === 'create'
    ? await client.createSession({ action: input.action, binding: input.binding, type: 'pow' })
    : await client.verify({ token: input.token, action: input.action, binding: input.binding });
  console.log(JSON.stringify(result));
} catch {
  console.error('Verification rejected');
  process.exitCode = 1;
}

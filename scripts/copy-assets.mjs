import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
await cp('web/public', 'internal/web/dist', { recursive: true });
// These are the Lit packages bundled by the browser build. The Node-only
// ssr-dom-shim is not part of the browser distribution.
const packages = ['lit', 'lit-element', 'lit-html', '@lit/reactive-element'];
let notice = 'Third-party code bundled into the Jervis Captcha browser widget.\n\n';
for (const name of packages) {
  const json = JSON.parse(await readFile(`node_modules/${name}/package.json`, 'utf8'));
  const license = await readFile(`node_modules/${name}/LICENSE`, 'utf8');
  notice += `--- ${name}@${json.version} ---\n${license}\n\n`;
}
await writeFile('internal/web/dist/THIRD_PARTY_NOTICES.txt', notice);
for (const destination of ['dist/web', 'packages/php/resources/dist', 'packages/frontend/dist']) {
  await rm(destination, { recursive: true, force: true });
  await mkdir(destination, { recursive: true });
  await cp('internal/web/dist', destination, { recursive: true });
}
await cp('packages/frontend/index.d.ts', 'packages/frontend/dist/index.d.ts');

// Keep the embedded PHP audio renderer on the exact generated digit samples
// used by the native engine. This is a local build-time copy; there is no
// runtime TTS or network dependency.
await mkdir('packages/php/resources/audio', { recursive: true });
await cp('internal/captcha/audio_samples.go', 'packages/php/resources/audio/digits.asset');

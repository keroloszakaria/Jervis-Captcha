import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';

// Native/demo distribution keeps the browser playground alongside the compiled
// widget. Package distributions below intentionally receive runtime files only.
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

await rm('dist/web', { recursive: true, force: true });
await mkdir('dist/web', { recursive: true });
await cp('internal/web/dist', 'dist/web', { recursive: true });

const runtimeFiles = ['jervis-captcha.js', 'THIRD_PARTY_NOTICES.txt'];
for (const destination of ['packages/php/resources/dist', 'packages/frontend/dist']) {
  await rm(destination, { recursive: true, force: true });
  await mkdir(destination, { recursive: true });
  for (const file of runtimeFiles) {
    await cp(`internal/web/dist/${file}`, `${destination}/${file}`);
  }
}
await cp('packages/frontend/index.d.ts', 'packages/frontend/dist/index.d.ts');

// Keep the embedded PHP audio renderer on the exact generated digit samples
// used by the native engine. This is a local build-time copy; there is no
// runtime TTS or network dependency.
await mkdir('packages/php/resources/audio', { recursive: true });
await cp('internal/captcha/audio_samples.go', 'packages/php/resources/audio/digits.asset');

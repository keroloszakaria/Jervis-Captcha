import { cp, mkdir, readFile, writeFile } from 'node:fs/promises';
await cp('web/public', 'internal/web/dist', { recursive: true });
await mkdir('dist', { recursive: true });
await cp('internal/web/dist', 'dist/web', { recursive: true });
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
await writeFile('dist/web/THIRD_PARTY_NOTICES.txt', notice);

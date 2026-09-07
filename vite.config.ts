import { defineConfig } from 'vite';
export default defineConfig({
  build: {
    outDir: 'internal/web/dist', emptyOutDir: true,
    lib: { entry: 'web/src/index.ts', name: 'JervisCaptcha', formats: ['es'], fileName: () => 'jervis-captcha.js' },
    target: 'es2022', sourcemap: false,
  },
  worker: { format: 'es' },
});

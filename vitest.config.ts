import { defineConfig } from 'vitest/config';
export default defineConfig({ test: { include: ['web/tests/**/*.test.ts', 'sdk/node/**/*.test.ts'], environment: 'node' } });

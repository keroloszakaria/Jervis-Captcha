import { defineConfig } from 'vitepress';
export default defineConfig({
  title: 'Jervis Captcha', description: 'Offline-first verification for web applications',
  themeConfig: { nav: [{ text: 'Guide', link: '/quickstart' }, { text: 'API', link: '/api' }], sidebar: [
    { text: 'Get started', items: [{ text: 'Overview', link: '/' }, { text: 'Quick start', link: '/quickstart' }, { text: 'Integrations', link: '/integrations' }] },
    { text: 'Engineering', items: [{ text: 'Architecture', link: '/architecture' }, { text: 'API', link: '/api' }, { text: 'Offline operations', link: '/offline' }, { text: 'Acceptance and roadmap', link: '/roadmap' }] },
  ] },
});

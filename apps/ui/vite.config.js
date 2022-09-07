import { sveltekit } from '@sveltejs/kit/vite';

/** @type {import('vite').UserConfig} */
export default {
    plugins: [sveltekit()],
    define: {
        'GITPOD_WORKSPACE_URL': JSON.stringify(process.env.GITPOD_WORKSPACE_URL),
        'CODESANDBOX_HOST': JSON.stringify(process.env.CODESANDBOX_HOST),
    },
    server: {
        host: '0.0.0.0',
        port: 3000,
        hmr: process.env.GITPOD_WORKSPACE_URL
        ? {
            // Due to port fowarding, we have to replace
            // 'https' with the forwarded port, as this
            // is the URI created by Gitpod.
            host: process.env.GITPOD_WORKSPACE_URL.replace("https://", "3000-"),
            protocol: "wss",
            clientPort: 443
          }
        : true,
        fs: {
            allow: ['./src/lib/locales/']
        }
    },
}
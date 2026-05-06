import { defineConfig, loadEnv } from "vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '')
    const viteHost = env.VITE_HOST || null;
    const vitePort = Number(env.VITE_PORT || 5173);

    return {
        server: {
            watch: {
                ignored: [
                    "**/dev_*_data/**",
                    "**/storage/**",
                ],
            },
            host: "0.0.0.0",
            allowedHosts: true,
            cors: {
                origin: [
                    /^https?:\/\/localhost(:\d+)?$/,
                    /^https?:\/\/127\.0\.0\.1(:\d+)?$/,
                    /^https?:\/\/\[::1\](:\d+)?$/,
                    ...(env.APP_URL ? [env.APP_URL] : []),
                    ...(viteHost ? [`http://${viteHost}:${vitePort}`, `https://${viteHost}:${vitePort}`] : []),
                ],
            },
            origin: viteHost ? `http://${viteHost}:${vitePort}` : undefined,
            hmr: viteHost
                ? { host: viteHost, clientPort: vitePort }
                : true,
        },
        plugins: [
            laravel({
                input: ["resources/css/app.css", "resources/js/app.js"],
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
        resolve: {
            alias: {
                vue: "vue/dist/vue.esm-bundler.js",
            },
        },
    }
});

import { defineConfig, loadEnv } from "vite";
import laravel from "laravel-vite-plugin";
import react from "@vitejs/plugin-react";
import inertia from "@inertiajs/vite";
import { fileURLToPath, URL } from "node:url";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '')
    const viteHost = env.VITE_HOST || null;
    const viteHmrHost = env.VITE_HMR_HOST || null;
    const vitePort = Number(env.VITE_PORT || 5173);

    return {
        resolve: {
            alias: {
                "@": fileURLToPath(new URL("./resources/js/v5", import.meta.url)),
            },
        },
        server: {
            watch: {
                ignored: [
                    "**/dev_*_data/**",
                    "**/storage/**",
                ],
            },
            host: "0.0.0.0",
            allowedHosts: true,
            cors: true,
            origin: viteHost ? `http://${viteHost}:${vitePort}` : undefined,
            hmr: viteHmrHost
                ? { host: viteHmrHost, clientPort: vitePort }
                : true,
        },
        plugins: [
            laravel({
                input: [
                    "resources/css/app.css",
                    "resources/js/app.js",
                    "resources/js/v5/app.tsx",
                ],
                refresh: true,
            }),
            inertia({ ssr: false }),
            react(),
        ],
    }
});

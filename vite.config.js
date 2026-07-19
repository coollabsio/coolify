import { defineConfig, loadEnv } from "vite";
import laravel from "laravel-vite-plugin";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), "");
    // Prefer process.env so Docker Compose can override without writing .env.
    // Set VITE_HOST to a browser-reachable hostname/IP when accessing the app
    // from another device (LAN / Tailscale), e.g. VITE_HOST=100.75.155.70
    const viteHost = (process.env.VITE_HOST || env.VITE_HOST || "localhost").trim();
    const vitePort = Number(process.env.VITE_PORT || env.VITE_PORT || 5173);

    return {
        server: {
            watch: {
                ignored: ["**/dev_*_data/**", "**/storage/**"],
            },
            // Listen on all interfaces so Docker / remote clients can reach the dev server
            host: "0.0.0.0",
            port: vitePort,
            strictPort: true,
            allowedHosts: true,
            // App (:8000) and Vite (:5173) are different origins; allow any host in dev
            cors: true,
            origin: `http://${viteHost}:${vitePort}`,
            hmr: {
                host: viteHost,
                clientPort: vitePort,
            },
        },
        plugins: [
            laravel({
                input: ["resources/css/app.css", "resources/js/app.js"],
                refresh: true,
            }),
        ],
    };
});

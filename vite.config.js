import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";

export default defineConfig({
    server: {
        host: "0.0.0.0",
        hmr: process.env.GITPOD_WORKSPACE_URL
            ? {
                  // Due to port forwarding, we have to replace
                  // 'https' with the forwarded port, as this
                  // is the URI created by GitPod.
                  host: process.env.GITPOD_WORKSPACE_URL.replace(
                      "https://",
                      "5173-"
                  ),
                  protocol: "wss",
                  clientPort: 443,
              }
            : {
                  host: "localhost",
              },
    },
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.js"],
            refresh: true,
        }),
    ],
});

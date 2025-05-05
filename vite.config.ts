import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/js/app.js', 'resources/css/app.css'],
            refresh: true,
        }),
        vue(),
    ],
});

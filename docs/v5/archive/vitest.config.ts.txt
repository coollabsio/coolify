import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js/v5', import.meta.url)),
        },
    },
    test: {
        environment: 'node',
        include: ['resources/js/v5/**/*.test.{ts,tsx}'],
    },
});

import { createInertiaApp } from '@inertiajs/react';

// Shared design system with v5 (tokens, Tailwind, shadcn base styles).
import '../../css/v5/app.css';

createInertiaApp({
    id: 'v4-app',
    pages: {
        path: './Pages',
        extension: '.tsx',
    },
    strictMode: true,
    progress: {
        delay: 10,
        color: '#fcd452',
        showSpinner: false,
    },
});

import { createInertiaApp } from '@inertiajs/react';
import '../../css/v5/app.css';

createInertiaApp({
    id: 'v5-app',
    pages: {
        path: './Pages',
        extension: '.tsx',
    },
    strictMode: true,
});

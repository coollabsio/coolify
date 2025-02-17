import { createApp, h } from 'vue'
import { ZiggyVue } from 'ziggy-js';
import { createInertiaApp } from '@inertiajs/vue3'
import { route } from './route'

createInertiaApp({
    progress: {
        color: '#6B16ED',
        showSpinner: true,
    },
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
        return pages[`./Pages/${name}.vue`]
    },
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
        app.use(ZiggyVue)
        app.use(plugin)
        app.provide('route', route)
        app.mount(el)
    },
})

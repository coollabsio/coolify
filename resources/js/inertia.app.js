import { createApp, h } from 'vue'
import { ZiggyVue } from 'ziggy-js';
import { createInertiaApp } from '@inertiajs/vue3'

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
        createApp({ render: () => h(App, props) })
            .use(ZiggyVue)
            .use(plugin)
            .mount(el)
    },
})

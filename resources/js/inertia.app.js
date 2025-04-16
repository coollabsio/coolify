import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
// import { autoAnimatePlugin } from '@formkit/auto-animate/vue'
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
        app.use(plugin)
        // app.use(autoAnimatePlugin)
        // app.provide('echo', window.Echo)
        // app.provide('pusher', window.Pusher)
        app.mount(el)
    },
})
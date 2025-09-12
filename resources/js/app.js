import { createApp, h } from 'vue'
import { createInertiaApp as createVueInertiaApp } from '@inertiajs/vue3'
import { createInertiaApp as createSvelteInertiaApp } from '@inertiajs/svelte'
import { mount } from 'svelte'
import Layout from './Layout.vue'
import { ZiggyVue } from 'ziggy-js';

const initialPage = window.page || JSON.parse(document.getElementById('app').dataset.page || '{}')
const pageName = initialPage.component

const isSveltePage = pageName && pageName.startsWith('Svelte/')

if (isSveltePage) {
    // Render svelte pages with the Svelte Inertia adapter
    createSvelteInertiaApp({
        resolve: name => {
            const pages = import.meta.glob('./Pages/**/*.svelte', { eager: true })
            return pages[`./Pages/${name}.svelte`]
        },
        setup({ el, App, props }) {
            mount(App, { target: el, props })
        },
    })
} else {
    // Render vue pages with the Vue Inertia adapter
    createVueInertiaApp({
        resolve: name => {
            const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
            let page = pages[`./Pages/${name}.vue`]
            page.default.layout = page.default.layout || Layout
            return page
        },
        setup({ el, App, props, plugin }) {
            createApp({ render: () => h(App, props) })
                .use(plugin)
                .use(ZiggyVue)
                .mount(el)
        },
    })
}

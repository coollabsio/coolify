import '../css/app.css'

import { createInertiaApp } from '@inertiajs/svelte'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { mount, type Component } from 'svelte'

createInertiaApp({
    resolve: (name: string) => resolvePageComponent(`./pages/${name}.svelte`, import.meta.glob('./pages/**/*.svelte')),
    setup({ el, App, props }: { el: HTMLElement; App: Component; props: Record<string, unknown> }) {
        mount(App, { target: el, props })
    }
})

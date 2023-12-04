import Alpine from "alpinejs";
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { createApp } from "vue";
import MagicBar from "./components/MagicBar.vue";
import Toaster from "../../vendor/masmerise/livewire-toaster/resources/js";

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    cluster: import.meta.env.VITE_PUSHER_HOST ?? '127.0.0.1',
    key: import.meta.env.VITE_PUSHER_APP_KEY ?? 'coolify',
    wsHost: import.meta.env.VITE_PUSHER_HOST ?? '127.0.0.1',
    wsPort: import.meta.env.VITE_PUSHER_PORT,
    wssPort: import.meta.env.VITE_PUSHER_PORT,
    forceTLS: false,
    encrypted: true,
    disableStats: false,
    enableLogging: true,
    enabledTransports: ['ws', 'wss'],
});

Alpine.plugin(Toaster);

window.Alpine = Alpine;
Alpine.start();

const app = createApp({});
app.component("magic-bar", MagicBar);
app.mount("#vue");




import Alpine from "alpinejs";
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { createApp } from "vue";
import MagicBar from "./components/MagicBar.vue";
import Toaster from "../../vendor/masmerise/livewire-toaster/resources/js";

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    cluster: import.meta.env.VITE_PUSHER_HOST || window.location.hostname,
    key: import.meta.env.VITE_PUSHER_APP_KEY,
    wsHost: import.meta.env.VITE_PUSHER_HOST || window.location.hostname,
    wsPort: import.meta.env.VITE_PUSHER_PORT || 6001,
    wssPort: import.meta.env.VITE_PUSHER_PORT || 6001,
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




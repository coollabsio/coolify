import Alpine from "alpinejs";
import focus from '@alpinejs/focus';
import { createApp } from "vue";
import MagicBar from "./components/MagicBar.vue";
import Toaster from "../../vendor/masmerise/livewire-toaster/resources/js";
import "../../vendor/wire-elements/modal/resources/js/modal";

Alpine.plugin(focus);
Alpine.plugin(Toaster);

window.Alpine = Alpine;
Alpine.start();

const app = createApp({});
app.component("magic-bar", MagicBar);
app.mount("#vue");




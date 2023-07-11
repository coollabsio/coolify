import Alpine from "alpinejs";
import "flowbite";
import { createApp } from "vue";
import MagicBar from "./components/MagicBar.vue";
import Toaster from "../../vendor/masmerise/livewire-toaster/resources/js";

Alpine.plugin(Toaster);

window.Alpine = Alpine;
Alpine.start();

const app = createApp({});
app.component("magic-bar", MagicBar);
app.mount("#vue");

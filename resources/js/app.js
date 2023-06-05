import Alpine from "alpinejs";

import { createApp } from "vue";
import MagicBar from "./components/MagicBar.vue";
window.Alpine = Alpine;
Alpine.start();

const app = createApp({});
app.component("magic-bar", MagicBar);
app.mount("#vue");

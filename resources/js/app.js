import { createApp } from "vue";
import MagicBar from "./components/MagicBar.vue";
import "../../vendor/wire-elements/modal/resources/js/modal";

const app = createApp({});
app.component("magic-bar", MagicBar);
app.mount("#vue");

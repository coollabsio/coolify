// import { createApp } from "vue";
// import MagicBar from "./components/MagicBar.vue";

// const app = createApp({});
// app.component("magic-bar", MagicBar);
// app.mount("#vue");

import { initializeTerminalComponent } from './terminal.js';

['livewire:navigated', 'alpine:init'].forEach((event) => {
    document.addEventListener(event, () => {
        // tree-shaking
        if (document.getElementById('terminal-container')) {
            initializeTerminalComponent()
        }
    });
});

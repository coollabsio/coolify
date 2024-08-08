// import { createApp } from "vue";
// import MagicBar from "./components/MagicBar.vue";

// const app = createApp({});
// app.component("magic-bar", MagicBar);
// app.mount("#vue");

import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { FitAddon } from '@xterm/addon-fit';

if (!window.term) {
    window.term = new Terminal({
        cols: 80,
        rows: 30,
        fontFamily: '"Fira Code", courier-new, courier, monospace, "Powerline Extra Symbols"',
        cursorBlink: true
    });
    window.fitAddon = new FitAddon();
    window.term.loadAddon(window.fitAddon);
}

import { initializeTerminalComponent } from './terminal.js';
import { initializeColorUtils } from './color-utils.js';

// Initialize color utilities globally
initializeColorUtils();

['livewire:navigated', 'alpine:init'].forEach((event) => {
    document.addEventListener(event, () => {
        // tree-shaking
        if (document.getElementById('terminal-container')) {
            initializeTerminalComponent()
        }
    });
});

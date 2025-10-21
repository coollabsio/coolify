import { initializeTerminalComponent } from './terminal.js';

// Initialize terminal component on alpine:init to ensure it's registered before Alpine processes the DOM
document.addEventListener('alpine:init', () => {
    // Register terminal component data (will be no-op if already registered)
    initializeTerminalComponent()
});

// Re-initialize on livewire:navigated for proper cleanup and setup
document.addEventListener('livewire:navigated', () => {
    // tree-shaking: only initialize if terminal container exists
    if (document.getElementById('terminal-container')) {
        // Component data is already registered, this will just reinitialize if needed
        initializeTerminalComponent()
    }
});

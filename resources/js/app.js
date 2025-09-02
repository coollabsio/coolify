import { initializeTerminalComponent } from './terminal.js';
import { createApp } from 'vue';

// Import Vue components for file browser
import FileBrowser from './components/FileBrowser.vue';
import FileUpload from './components/FileUpload.vue';
import FilePermissions from './components/FilePermissions.vue';

// Initialize Vue app for file browser
const initializeVueComponents = () => {
    const fileBrowserElement = document.getElementById('file-browser-app');
    if (fileBrowserElement) {
        const app = createApp({});
        
        // Register file browser components
        app.component('file-browser', FileBrowser);
        app.component('file-upload', FileUpload);
        app.component('file-permissions', FilePermissions);
        
        app.mount('#file-browser-app');
        
        // Store the app instance for cleanup if needed
        window.fileBrowserApp = app;
    }
};

['livewire:navigated', 'alpine:init'].forEach((event) => {
    document.addEventListener(event, () => {
        // Existing terminal initialization
        if (document.getElementById('terminal-container')) {
            initializeTerminalComponent()
        }
        
        // Initialize Vue file browser components
        initializeVueComponents();
    });
});

// Also initialize on DOM content loaded
document.addEventListener('DOMContentLoaded', initializeVueComponents);
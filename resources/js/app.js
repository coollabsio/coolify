import { initializeTerminalComponent } from './terminal.js';

['livewire:navigated', 'alpine:init'].forEach((event) => {
    document.addEventListener(event, () => {
        // tree-shaking
        if (document.getElementById('terminal-container')) {
            initializeTerminalComponent()
        }
    });
});

// Listen for generated passwords broadcast
document.addEventListener('livewire:init', () => {
    if (window.Echo && window.currentUserId) {
        // Listen for the event and dispatch to Livewire
        window.Echo.private(`user.${window.currentUserId}`)
            .listen('GeneratedPasswordsEvent', (e) => {
                window.Livewire.dispatch('showGeneratedPasswords', e);
            });
    }
});

import { initializeTerminalComponent } from './terminal.js';

// Livewire 3.5.19+ re-applies `x-cloak` to morphed elements during wire:navigate
// (via replaceHtmlAttributes). With `[x-cloak]{display:none}` on the app wrapper,
// this blanks the whole page on every navigation until Alpine re-processes it.
// Strip leftover x-cloak after each navigation; the initial-load FOUC guard stays.
document.addEventListener('livewire:navigated', () => {
    document.querySelectorAll('[x-cloak]').forEach((el) => el.removeAttribute('x-cloak'));
});

// Register the terminal data provider before Alpine initializes the page.
// Keeping this registration independent from the current route also makes it
// available before Alpine processes terminal markup after wire:navigate.
document.addEventListener('alpine:init', initializeTerminalComponent);

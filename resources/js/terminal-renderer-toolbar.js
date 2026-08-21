// Shared Alpine state + behavior for the console renderer selector toolbar.
//
// Both terminal toolbars (the standalone Terminal page and the container command
// page) spread this into their inline x-data so the renderer selector's state,
// persistence, and event wiring live in one place. The renderer metadata (names,
// descriptions) lives in config/terminal.php and the selector Blade component.

import { TERMINAL_RENDERER_STORAGE_KEY, resolveTerminalRenderer } from './terminal-renderer.js';

/**
 * Build the shared renderer-toolbar Alpine fragment.
 *
 * Spread into a toolbar's x-data and call `initRenderer()` from the toolbar's
 * own `init()` (Alpine only invokes one `init`, so this factory intentionally
 * exposes `initRenderer` instead).
 *
 * @returns {{ consoleRenderer: string, rendererOpen: boolean, initRenderer: Function, setRenderer: Function }}
 */
export function terminalRendererToolbar() {
    return {
        consoleRenderer: 'xterm',
        rendererOpen: false,

        initRenderer() {
            this.consoleRenderer = resolveTerminalRenderer(localStorage.getItem(TERMINAL_RENDERER_STORAGE_KEY));
            localStorage.setItem(TERMINAL_RENDERER_STORAGE_KEY, this.consoleRenderer);

            // Converge the selector when the terminal falls back to xterm because
            // the experimental renderer failed to load.
            window.addEventListener('terminal-renderer-fallback', (event) => {
                this.consoleRenderer = resolveTerminalRenderer(event.detail?.renderer);
                localStorage.setItem(TERMINAL_RENDERER_STORAGE_KEY, this.consoleRenderer);
            });
        },

        setRenderer(renderer) {
            this.consoleRenderer = resolveTerminalRenderer(renderer);
            this.rendererOpen = false;
            localStorage.setItem(TERMINAL_RENDERER_STORAGE_KEY, this.consoleRenderer);
            window.dispatchEvent(new CustomEvent('terminal-renderer-change', {
                detail: { renderer: this.consoleRenderer },
            }));
        },
    };
}

// Expose to inline Alpine x-data. Assigned at module load (before alpine:init)
// so the toolbars can spread `...window.terminalRendererToolbar()`.
if (typeof window !== 'undefined') {
    window.terminalRendererToolbar = terminalRendererToolbar;
}

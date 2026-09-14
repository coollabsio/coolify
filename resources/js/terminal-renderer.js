// Terminal renderer selection.
//
// The console can render with two frontends that share the same websocket/PTY
// backend: xterm.js (default, stable) and ghostty-web (experimental, Ghostty's
// VT parser compiled to WASM). The preference is stored per browser in
// localStorage, mirroring the color-theme preference.
//
// ghostty-web is xterm-API-compatible with a few exceptions this module and
// terminal.js account for:
//   - it has no `.refresh()` method
//   - `attachCustomKeyEventHandler` uses the INVERSE return convention
//   - it ships its own FitAddon (do not feed it `@xterm/addon-fit`)
//   - it needs a settled paint before the first writes render

export const TERMINAL_RENDERER_STORAGE_KEY = 'coolify-console-renderer';
export const DEFAULT_TERMINAL_RENDERER = 'xterm';
export const AVAILABLE_TERMINAL_RENDERERS = ['xterm', 'ghostty'];

/**
 * Normalize a stored/requested renderer value to a supported renderer,
 * falling back to the default for anything unknown.
 *
 * @param {unknown} value
 * @returns {'xterm'|'ghostty'}
 */
export function resolveTerminalRenderer(value) {
    return AVAILABLE_TERMINAL_RENDERERS.includes(value) ? value : DEFAULT_TERMINAL_RENDERER;
}

/**
 * Translate an xterm-style custom-key-event-handler return value to the
 * convention the active renderer expects.
 *
 * xterm.js: return `false` to stop the terminal from processing the key
 * (i.e. the app/browser handled it). ghostty-web inverts this — `true` means
 * "custom handler consumed / prevented default". So for ghostty we flip the
 * boolean; for xterm we pass it through unchanged.
 *
 * @param {'xterm'|'ghostty'} renderer
 * @param {boolean} xtermStyleResult
 * @returns {boolean}
 */
export function translateKeyEventResult(renderer, xtermStyleResult) {
    return renderer === 'ghostty' ? !xtermStyleResult : xtermStyleResult;
}

/**
 * Create a terminal instance and its fit addon for the requested renderer.
 *
 * The xterm constructors are injected (terminal.js already imports them
 * statically), while ghostty-web is imported lazily so its ~400KB WASM only
 * downloads when a user actually selects it. ghostty-web must have its async
 * `init()` awaited before the first `Terminal` is constructed.
 *
 * @param {'xterm'|'ghostty'} renderer
 * @param {object} options terminal constructor options (shared; unknown keys are ignored by ghostty-web)
 * @param {{ XtermTerminal: Function, XtermFitAddon: Function }} deps
 * @returns {Promise<{ term: object, fitAddon: object }>}
 */
export async function createTerminal(renderer, options, { XtermTerminal, XtermFitAddon }) {
    if (renderer === 'ghostty') {
        const { init, Terminal, FitAddon } = await import('ghostty-web');
        await init();
        const term = new Terminal(options);
        const fitAddon = new FitAddon();
        term.loadAddon(fitAddon);
        return { term, fitAddon };
    }

    const term = new XtermTerminal(options);
    const fitAddon = new XtermFitAddon();
    term.loadAddon(fitAddon);
    return { term, fitAddon };
}

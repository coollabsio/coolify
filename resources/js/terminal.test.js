import test from 'node:test';
import assert from 'node:assert/strict';
import {
    MAX_TERMINAL_SESSION_SECONDS,
    formatTerminalSessionRemainingTime,
} from './terminal-session-timer.js';
import {
    DEFAULT_TERMINAL_RENDERER,
    resolveTerminalRenderer,
    translateKeyEventResult,
    createTerminal,
} from './terminal-renderer.js';

test('formatTerminalSessionRemainingTime formats the eight hour terminal limit countdown', () => {
    assert.equal(MAX_TERMINAL_SESSION_SECONDS, 8 * 60 * 60);
    assert.equal(formatTerminalSessionRemainingTime(MAX_TERMINAL_SESSION_SECONDS), '8h 00m 00s');
    assert.equal(formatTerminalSessionRemainingTime((7 * 60 * 60) + (59 * 60) + 59), '7h 59m 59s');
    assert.equal(formatTerminalSessionRemainingTime(65 * 60), '1h 05m 00s');
    assert.equal(formatTerminalSessionRemainingTime(59), '0m 59s');
    assert.equal(formatTerminalSessionRemainingTime(0), 'expired');
});

test('resolveTerminalRenderer only accepts known renderers and defaults to xterm', () => {
    assert.equal(DEFAULT_TERMINAL_RENDERER, 'xterm');
    assert.equal(resolveTerminalRenderer('xterm'), 'xterm');
    assert.equal(resolveTerminalRenderer('ghostty'), 'ghostty');
    assert.equal(resolveTerminalRenderer('unknown'), 'xterm');
    assert.equal(resolveTerminalRenderer(null), 'xterm');
    assert.equal(resolveTerminalRenderer(undefined), 'xterm');
});

test('translateKeyEventResult inverts only for ghostty', () => {
    // xterm passes through: false = terminal must NOT process the key.
    assert.equal(translateKeyEventResult('xterm', false), false);
    assert.equal(translateKeyEventResult('xterm', true), true);
    // ghostty inverts: true = custom handler consumed / prevented default.
    assert.equal(translateKeyEventResult('ghostty', false), true);
    assert.equal(translateKeyEventResult('ghostty', true), false);
});

test('createTerminal builds an xterm terminal from injected constructors and loads the fit addon', async () => {
    const loaded = [];
    class FakeTerminal {
        constructor(options) {
            this.options = options;
        }
        loadAddon(addon) {
            loaded.push(addon);
        }
    }
    class FakeFitAddon {}

    const options = { cols: 80, rows: 30 };
    const { term, fitAddon } = await createTerminal('xterm', options, {
        XtermTerminal: FakeTerminal,
        XtermFitAddon: FakeFitAddon,
    });

    assert.ok(term instanceof FakeTerminal);
    assert.equal(term.options, options);
    assert.ok(fitAddon instanceof FakeFitAddon);
    assert.deepEqual(loaded, [fitAddon]);
});

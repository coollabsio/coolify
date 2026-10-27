import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import {
    MAX_TERMINAL_SESSION_SECONDS,
    formatTerminalSessionRemainingTime,
} from './terminal-session-timer.js';
import * as terminalConnection from './terminal-connection.js';

test('formatTerminalSessionRemainingTime formats the eight hour terminal limit countdown', () => {
    assert.equal(MAX_TERMINAL_SESSION_SECONDS, 8 * 60 * 60);
    assert.equal(formatTerminalSessionRemainingTime(MAX_TERMINAL_SESSION_SECONDS), '8h 00m 00s');
    assert.equal(formatTerminalSessionRemainingTime((7 * 60 * 60) + (59 * 60) + 59), '7h 59m 59s');
    assert.equal(formatTerminalSessionRemainingTime(65 * 60), '1h 05m 00s');
    assert.equal(formatTerminalSessionRemainingTime(59), '0m 59s');
    assert.equal(formatTerminalSessionRemainingTime(0), 'expired');
});

function terminalComponent() {
    let factory;
    const storage = new Map([['coolify-console-history:server:container', '["secret"]']]);
    const localStorage = {
        get length() { return storage.size; },
        key: (index) => [...storage.keys()][index],
        getItem: (key) => storage.get(key) ?? null,
        setItem: (key, value) => storage.set(key, value),
        removeItem: (key) => storage.delete(key),
    };
    const source = readFileSync(new URL('./terminal.js', import.meta.url), 'utf8')
        .split('export function initializeTerminalComponent')[1];
    const componentSource = `function initializeTerminalComponent${source}`;
    vm.runInNewContext(`${componentSource}\ninitializeTerminalComponent();`, {
        window: { Alpine: { data: (_name, value) => { factory = value; } } },
        localStorage, console,
        setTimeout, clearTimeout,
        MAX_TERMINAL_SESSION_SECONDS, applicationTerminalThemes: { system: {} },
        ...terminalConnection,
    });
    const component = factory();
    component.$wire = { terminalMode: 'attach', currentIdentifier: 'container', currentServerUuid: 'server', dispatch() {} };
    component.$refs = { consoleInput: { focus() {} } };
    component.$nextTick = (callback) => callback();
    component.term = { options: {}, focus() {} };
    component.terminalActive = true;
    return { component, storage };
}

test('console history does not persist sensitive commands or load previous users history', (t) => {
    const { component, storage } = terminalComponent();
    component.loadConsoleHistory();
    assert.equal(component.consoleHistory.length, 0);
    component.consoleCommand = 'secret command';
    const messages = [];
    component.sendMessage = (message) => messages.push(message.message);
    component.submitConsoleCommand();
    assert.deepEqual(messages, ['secret command\n']);
    assert.equal(component.consoleHistory.length, 1);
    assert.equal(storage.size, 0);
    component.loadConsoleHistory();
    assert.equal(component.consoleHistory.length, 0);
});

test('console startup and theme focus stay on the command input', (t) => {
    const { component } = terminalComponent();
    let consoleFocus = 0;
    let terminalFocus = 0;
    component.$refs.consoleInput.focus = () => { consoleFocus++; };
    component.term.focus = () => { terminalFocus++; };
    component.focusActiveInput();
    assert.equal(consoleFocus, 1);
    assert.equal(terminalFocus, 0);
    component.$wire.terminalMode = 'shell';
    component.focusActiveInput();
    assert.equal(terminalFocus, 1);
});

test('console input cannot forward control sequences or send into a shell session', () => {
    const { component } = terminalComponent();
    const messages = [];
    component.sendMessage = (message) => messages.push(message.message);
    for (const command of ['\x03', 'list\nstop', '\x10\x11']) {
        component.consoleCommand = command;
        component.submitConsoleCommand();
    }
    component.$wire.terminalMode = 'shell';
    component.consoleCommand = 'stop';
    component.submitConsoleCommand();
    component.$wire.terminalMode = 'attach';
    component.terminalActive = false;
    component.submitConsoleCommand();
    assert.equal(messages.length, 0);
});

function systemTerminalTheme({ theme, dark }) {
    const source = readFileSync(new URL('./terminal.js', import.meta.url), 'utf8');
    const themeSource = source.slice(source.indexOf('const baseApplicationTerminalTheme'), source.indexOf('const applicationTerminalThemes'));
    return vm.runInNewContext(`${themeSource}\ncreateSystemTerminalTheme();`, {
        document: { documentElement: { dataset: { theme }, classList: { contains: (name) => name === 'dark' && dark } } },
        localStorage: { getItem: (key) => (key === 'themeColor' ? '#c2410c' : null) },
    });
}

test('system terminal theme uses dark text on light custom themes and light text on dark custom themes', () => {
    const lightCustom = systemTerminalTheme({ theme: 'custom', dark: false });
    assert.equal(lightCustom.foreground, '#18181b');
    assert.equal(lightCustom.brightGreen, '#166534');
    assert.equal(lightCustom.cursor, systemTerminalTheme({ theme: 'custom', dark: true }).cursor);
    assert.equal(systemTerminalTheme({ theme: 'custom', dark: true }).foreground, '#eee9f2');
    assert.equal(systemTerminalTheme({ theme: 'light', dark: false }).cursor, '#52525b');
});

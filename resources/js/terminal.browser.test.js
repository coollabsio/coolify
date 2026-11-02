import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test('pty-ready keeps the console input focused after the 500 ms retry', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        await page.route('http://terminal.test/', route => route.fulfill({ contentType: 'text/html', body: '<div class="xterm-viewport" tabindex="0">Container output</div><input id="consoleInput" placeholder="Console command">' }));
        await page.goto('http://terminal.test/');
        const source = readFileSync(new URL('./terminal.js', import.meta.url), 'utf8')
            .split('export function initializeTerminalComponent')[1];
        // terminal.js imports these helpers; load them as plain declarations.
        const connectionSource = readFileSync(new URL('./terminal-connection.js', import.meta.url), 'utf8')
            .replace(/^export /gm, '');
        await page.evaluate(([connectionSource, source]) => {
            window.Alpine = { data: (_name, factory) => { window.terminalFactory = factory; } };
            window.applicationTerminalThemes = { system: {} };
            window.MAX_TERMINAL_SESSION_SECONDS = 28800;
            window.logTerminal = () => {};
            // Register the actual Alpine component, without its Vite imports.
            new Function(`${connectionSource}\nfunction initializeTerminalComponent${source}\ninitializeTerminalComponent();`)();
            const component = window.terminalFactory();
            component.$wire = { terminalMode: 'attach', dispatch() {} };
            component.$refs = { consoleInput: document.querySelector('#consoleInput') };
            component.term = { _initialized: true, focus: () => document.querySelector('.xterm-viewport').focus() };
            component.startTerminalSessionCountdown = () => {};
            component.resizeTerminal = () => {};
            component.handleSocketMessage({ data: 'pty-ready' });
            window.terminalComponent = component;
        }, [connectionSource, source]);
        await page.waitForTimeout(650);
        assert.equal(await page.locator('#consoleInput').evaluate((input) => document.activeElement === input), true);
        await page.screenshot({ path: '/tmp/pr11538-console-focus.png' });
        await page.evaluate(() => {
            window.terminalComponent.$wire.terminalMode = 'shell';
            window.terminalComponent.handleSocketMessage({ data: 'pty-ready' });
        });
        await page.waitForTimeout(650);
        assert.equal(await page.locator('.xterm-viewport').evaluate((terminal) => document.activeElement === terminal), true);
    } finally {
        await browser.close();
    }
});

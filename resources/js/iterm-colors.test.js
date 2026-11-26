import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parseItermColors } from './iterm-colors.js';

const fixture = readFileSync(new URL('../../tests/Fixtures/iterm-theme.itermcolors', import.meta.url), 'utf8');

const color = (name, red, green, blue) => `<key>${name}</key><dict><key>Alpha Component</key><real>1</real>`
    + `<key>Blue Component</key><real>${blue}</real><key>Color Space</key><string>sRGB</string>`
    + `<key>Green Component</key><real>${green}</real><key>Red Component</key><real>${red}</real></dict>`;
const plist = (...colors) => `<?xml version="1.0" encoding="UTF-8"?><plist version="1.0"><dict>${colors.join('')}</dict></plist>`;

test('parseItermColors maps an iTerm2 file to the four Custom theme colors', () => {
    assert.deepEqual(parseItermColors(fixture), {
        sidebar: '#201716',
        selected: '#563c27',
        accent: '#5a86ad',
        background: '#2a1f1d',
    });
});

test('parseItermColors reads a file without line breaks, as pasted into a one-line field', () => {
    assert.deepEqual(parseItermColors(fixture.replace(/\r?\n/g, '')), parseItermColors(fixture));
});

test('parseItermColors returns null without a background and a foreground color', () => {
    assert.equal(parseItermColors(plist(color('Background Color', 0, 0, 0))), null);
    assert.equal(parseItermColors(plist(color('Foreground Color', 1, 1, 1))), null);
    assert.equal(parseItermColors('#111111,#222222,#333333'), null);
    assert.equal(parseItermColors('not xml'), null);
    assert.equal(parseItermColors(''), null);
});

test('parseItermColors falls back to the background mixed with the foreground and to Ansi 12', () => {
    assert.deepEqual(parseItermColors(plist(
        color('Background Color', 0, 0, 0),
        color('Foreground Color', 1, 1, 1),
        color('Ansi 12 Color', 0, 0, 1),
    )), { sidebar: '#000000', selected: '#292929', accent: '#0000ff', background: '#000000' });
});

test('parseItermColors uses the foreground as accent and shades the sidebar of a light theme', () => {
    assert.deepEqual(parseItermColors(plist(
        color('Background Color', 1, 1, 1),
        color('Foreground Color', 0, 0, 0.5),
        color('Selection Color', 1.2, 0.5, -0.1),
    )), { sidebar: '#f0f0f7', selected: '#ff8000', accent: '#000080', background: '#ffffff' });
});

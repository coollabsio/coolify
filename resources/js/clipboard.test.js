import test from 'node:test';
import assert from 'node:assert/strict';
import { copyTextToClipboard } from './clipboard.js';

class FakeClipboardItem {
    constructor(items) {
        this.items = items;
    }
}

function fakeClipboard({ writeFails = false } = {}) {
    const calls = [];
    return {
        calls,
        write(items) {
            calls.push('write');
            if (writeFails) {
                return Promise.reject(new Error('NotAllowedError'));
            }
            return items[0].items['text/plain'].then((blob) => blob.text()).then((text) => {
                this.copied = text;
            });
        },
        writeText(text) {
            calls.push('writeText');
            this.copied = text;
            return Promise.resolve();
        },
    };
}

test('starts the clipboard write before the text has loaded', async () => {
    const clipboard = fakeClipboard();
    let resolveText;
    const text = new Promise((resolve) => (resolveText = resolve));

    const copying = copyTextToClipboard(text, clipboard, FakeClipboardItem);
    assert.deepEqual(clipboard.calls, ['write']);

    resolveText('line 1\nline 2');
    await copying;
    assert.equal(clipboard.copied, 'line 1\nline 2');
});

test('falls back to writeText when ClipboardItem is not available', async () => {
    const clipboard = fakeClipboard();
    await copyTextToClipboard(Promise.resolve('logs'), clipboard, undefined);
    assert.deepEqual(clipboard.calls, ['writeText']);
    assert.equal(clipboard.copied, 'logs');
});

test('falls back to writeText when write rejects', async () => {
    const clipboard = fakeClipboard({ writeFails: true });
    await copyTextToClipboard('logs', clipboard, FakeClipboardItem);
    assert.deepEqual(clipboard.calls, ['write', 'writeText']);
    assert.equal(clipboard.copied, 'logs');
});

test('rejects and does not retry when the text fails to load', async () => {
    const clipboard = fakeClipboard();
    await assert.rejects(copyTextToClipboard(Promise.reject(new Error('server error')), clipboard, FakeClipboardItem), /server error/);
    assert.deepEqual(clipboard.calls, ['write']);
    assert.equal(clipboard.copied, undefined);
});

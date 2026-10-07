import test from 'node:test';
import assert from 'node:assert/strict';
import {
    createTimestampFormatter,
    filterLogLines,
    getLogLevel,
    highlightSegments,
    isFindShortcut,
    lastTimestamp,
    newLinesSince,
    parseLogLines,
} from './runtime-logs.js';

const idGenerator = () => {
    let id = 0;
    return () => ++id;
};

test('parses docker timestamped lines and skips blank lines', () => {
    const lines = parseLogLines(
        '2026-09-28T10:00:00.123456789Z Server started\n\n2026-09-28T10:00:01Z ERROR failed to connect\nplain line',
        idGenerator(),
    );

    assert.deepEqual(lines.map(({ id, ts, text, level }) => ({ id, ts, text, level })), [
        { id: 1, ts: '2026-09-28T10:00:00.123456789Z', text: 'Server started', level: 'info' },
        { id: 2, ts: '2026-09-28T10:00:01Z', text: 'ERROR failed to connect', level: 'error' },
        { id: 3, ts: '', text: 'plain line', level: 'info' },
    ]);
    assert.ok(Object.isFrozen(lines[0]));
});

test('detects log levels by keyword', () => {
    assert.equal(getLogLevel('fatal: boom'), 'error');
    assert.equal(getLogLevel('warning: disk'), 'warning');
    assert.equal(getLogLevel('trace id=1'), 'debug');
    assert.equal(getLogLevel('ready'), 'info');
});

test('filters by level and case-insensitive query', () => {
    const lines = parseLogLines('ERROR Database down\nINFO Database up\nwarn cache miss', idGenerator());

    assert.deepEqual(filterLogLines(lines, { error: true, warning: true, debug: true, info: true }, 'DATABASE').map((l) => l.text), [
        'ERROR Database down',
        'INFO Database up',
    ]);
    assert.deepEqual(filterLogLines(lines, { error: false, warning: true, debug: true, info: true }, '').map((l) => l.text), [
        'INFO Database up',
        'warn cache miss',
    ]);
});

test('drops lines that a --since request returns again', () => {
    const nextId = idGenerator();
    const existing = parseLogLines(
        '2026-09-28T10:00:00Z first\n2026-09-28T10:00:01Z same-second a\n2026-09-28T10:00:01Z same-second b',
        nextId,
    );
    const incoming = parseLogLines(
        '2026-09-28T10:00:01Z same-second a\n2026-09-28T10:00:01Z same-second b\n2026-09-28T10:00:01Z same-second c\n2026-09-28T10:00:02Z next',
        nextId,
    );

    assert.equal(lastTimestamp(existing), '2026-09-28T10:00:01Z');
    assert.deepEqual(newLinesSince(existing, incoming).map((l) => l.text), ['same-second c', 'next']);
});

test('keeps repeated identical lines that are new at the boundary', () => {
    const nextId = idGenerator();
    const existing = parseLogLines('2026-09-28T10:00:01Z ping', nextId);
    const incoming = parseLogLines('2026-09-28T10:00:01Z ping\n2026-09-28T10:00:01Z ping', nextId);

    assert.deepEqual(newLinesSince(existing, incoming).map((l) => l.text), ['ping']);
});

test('splits text into highlight segments without markup', () => {
    assert.deepEqual(highlightSegments('<b>Error</b> error', 'error'), [
        { text: '<b>', match: false },
        { text: 'Error', match: true },
        { text: '</b> ', match: false },
        { text: 'error', match: true },
    ]);
    assert.deepEqual(highlightSegments('plain', '  '), [{ text: 'plain', match: false }]);
});

test('formats timestamps in the server timezone and falls back to UTC', () => {
    assert.equal(createTimestampFormatter('Europe/Budapest')('2025-12-04T11:48:39.136764033Z'), '2025-Dec-04 12:48:39');
    assert.equal(createTimestampFormatter('Not/AZone')('2025-12-04T11:48:39Z'), '2025-Dec-04 11:48:39');
});

test('detects Ctrl+F and Cmd+F only', () => {
    assert.equal(isFindShortcut({ ctrlKey: true, key: 'f' }), true);
    assert.equal(isFindShortcut({ metaKey: true, key: 'F' }), true);
    assert.equal(isFindShortcut({ key: 'f' }), false);
    assert.equal(isFindShortcut({ ctrlKey: true, shiftKey: true, key: 'F' }), false);
    assert.equal(isFindShortcut({ ctrlKey: true, altKey: true, key: 'f' }), false);
    assert.equal(isFindShortcut({ ctrlKey: true, key: 'g' }), false);
});

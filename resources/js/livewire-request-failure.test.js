import test from 'node:test';
import assert from 'node:assert/strict';
import {
    GESTURE_WINDOW_MS,
    INFRASTRUCTURE_FAILURE_STATUSES,
    createLivewireRequestFailureHandler,
    registerLivewireRequestFailureHandler,
} from './livewire-request-failure.js';

function createHarness({ start = 100_000 } = {}) {
    let currentTime = start;
    let requestHook = null;
    let toasts = 0;
    let warnings = [];
    const listeners = {};

    global.window = { toast: () => toasts++ };
    global.console = { warn: (...args) => warnings.push(args) };

    registerLivewireRequestFailureHandler({
        hook(name, callback) {
            assert.equal(name, 'request');
            requestHook = callback;
        },
    }, {
        addEventListener(name, callback) {
            listeners[name] = callback;
        },
    }, { now: () => currentTime });

    return {
        listeners,
        advance: (ms) => currentTime += ms,
        gesture: (event = { isTrusted: true }) => listeners.click(event),
        fail(status) {
            let prevented = false;
            let failureCallback = null;
            requestHook({ fail: (callback) => failureCallback = callback });
            failureCallback({ status, content: '<html>proxy error</html>', preventDefault: () => prevented = true });
            return prevented;
        },
        toasts: () => toasts,
        warnings: () => warnings,
    };
}

test('a failure after a trusted gesture suppresses the response and shows a toast', () => {
    const harness = createHarness();

    harness.gesture();
    const prevented = harness.fail(504);

    assert.equal(prevented, true);
    assert.equal(harness.toasts(), 1);
});

test('background failures are suppressed and logged without a toast', () => {
    const harness = createHarness();

    const prevented = harness.fail(524);

    assert.equal(prevented, true);
    assert.equal(harness.toasts(), 0);
    assert.equal(harness.warnings().length, 1);
    assert.deepEqual(harness.warnings()[0], ['Livewire request failed', {
        status: 524,
        content: '<html>proxy error</html>',
    }]);
});

test('one gesture toasts once, but a retry gesture toasts again', () => {
    const harness = createHarness();

    harness.gesture();
    harness.fail(504);
    harness.fail(504);
    assert.equal(harness.toasts(), 1);

    harness.advance(8_000);
    harness.gesture();
    harness.fail(504);
    assert.equal(harness.toasts(), 2);
});

test('requests sent outside the gesture window count as background', () => {
    const harness = createHarness();

    harness.gesture();
    harness.advance(GESTURE_WINDOW_MS + 1);
    harness.fail(504);

    assert.equal(harness.toasts(), 0);
});

test('untrusted synthetic events do not count as gestures', () => {
    const harness = createHarness();

    harness.gesture({ isTrusted: false });
    harness.fail(504);

    assert.equal(harness.toasts(), 0);
});

test('a missing window.toast does not throw', () => {
    const harness = createHarness();
    delete global.window.toast;

    harness.gesture();
    assert.doesNotThrow(() => harness.fail(504));
});

test('console warnings are throttled and truncated', () => {
    const harness = createHarness();

    harness.fail(502);
    harness.fail(504);
    assert.equal(harness.warnings().length, 1);

    harness.advance(10_000);
    harness.fail(504);
    assert.equal(harness.warnings().length, 2);

    const handler = createLivewireRequestFailureHandler({ now: () => 0 });
    let logged = null;
    global.console = { warn: (message, details) => logged = details };
    handler({ status: 502, content: 'x'.repeat(5_000), preventDefault() {} });
    assert.equal(logged.content.length, 2_000);
});

test('all supported infrastructure status codes are handled', () => {
    const harness = createHarness();

    for (const status of INFRASTRUCTURE_FAILURE_STATUSES) {
        assert.equal(harness.fail(status), true, `expected ${status} to be handled`);
    }
});

test('other failures keep Livewire default handling', () => {
    const harness = createHarness();

    harness.gesture();
    for (const status of [401, 419, 422, 429, 500]) {
        assert.equal(harness.fail(status), false, `expected ${status} to be untouched`);
    }
    assert.equal(harness.toasts(), 0);
});

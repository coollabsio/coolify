import test from 'node:test';
import assert from 'node:assert/strict';
import { registerLivewireUpdateDedupe, removeSupersededUpdates } from './livewire-update-dedupe.js';

test('drops nested keys that a whole-property update replaces', () => {
    assert.deepEqual(
        removeSupersededUpdates({ 'ids.0': 1, ids: [1] }),
        { ids: [1] },
    );
    assert.deepEqual(
        removeSupersededUpdates({ 'ids.1': 3, 'ids.2': '__rm__', ids: [1, 3] }),
        { ids: [1, 3] },
    );
});

test('keeps nested keys without a parent update and similarly named properties', () => {
    assert.deepEqual(
        removeSupersededUpdates({ 'form.name': 'a', idsExtra: [2], ids: [1] }),
        { 'form.name': 'a', idsExtra: [2], ids: [1] },
    );
});

test('cleans the payload in the Livewire commit hook', () => {
    let commitHook = null;
    registerLivewireUpdateDedupe({
        hook(name, callback) {
            assert.equal(name, 'commit');
            commitHook = callback;
        },
    });

    const commit = { updates: { 'ids.0': 1, ids: [1] } };
    commitHook({ commit });

    assert.deepEqual(commit.updates, { ids: [1] });
});

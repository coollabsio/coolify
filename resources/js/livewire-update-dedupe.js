/**
 * Livewire 3 sends a nested diff (`ids.0`) next to a queued whole-property
 * update (`ids`) when an array changes through `.live` entangle or `$set`,
 * so `updatedIds()` runs once per key. Livewire 4 drops the superseded nested
 * keys in `mergeQueuedUpdates`; this backports that fix.
 *
 * @param {Record<string, unknown>} updates
 * @returns {Record<string, unknown>}
 */
export function removeSupersededUpdates(updates) {
    const keys = Object.keys(updates);

    keys.forEach((key) => {
        if (keys.some((parent) => key.startsWith(`${parent}.`))) {
            delete updates[key];
        }
    });

    return updates;
}

export function registerLivewireUpdateDedupe(Livewire) {
    Livewire.hook('commit', ({ commit }) => {
        removeSupersededUpdates(commit.updates);
    });
}

/**
 * `wire:navigate` stores the page for the back button by serializing
 * `document.documentElement.outerHTML` and re-parsing it later. Browser
 * extensions frequently inject their overlay host as the first child of
 * `<html>`, before `<head>`. Re-parsing such a string ends the head before it
 * starts, so the whole `<head>` is parsed into `<body>`: the restored page
 * renders broken, inline head scripts run a second time, and navigation stays
 * dead until a reload.
 *
 * Moving those strays behind `<head>` (but still outside `<body>`, so they
 * survive the body swap) keeps the snapshot round-tripping correctly.
 *
 * @param {Document} document
 * @return {number} How many elements were moved.
 */
export function moveStrayNodesBehindHead(document) {
    const root = document.documentElement;
    const head = document.head;
    const body = document.body;

    if (! root || ! head || ! body) {
        return 0;
    }

    let moved = 0;

    for (const child of Array.from(root.children)) {
        if (child === head) {
            break;
        }

        root.insertBefore(child, body);
        moved++;
    }

    return moved;
}

/**
 * Run the guard right before Livewire snapshots the current page.
 *
 * @param {Document} document
 */
export function registerNavigateSnapshotGuard(document) {
    document.addEventListener('livewire:navigating', () => moveStrayNodesBehindHead(document));
}

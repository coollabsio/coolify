// Copies text that can still be loading (for example logs from a Livewire call).
// WebKit (Safari and every iOS browser) accepts a clipboard write only while the
// click is still active, so an await before the write fails there. Giving
// ClipboardItem the pending Promise starts the write inside the click.
// https://webkit.org/blog/10855/async-clipboard-api/
export async function copyTextToClipboard(text, clipboard = globalThis.navigator?.clipboard, Item = globalThis.ClipboardItem) {
    const textPromise = Promise.resolve(text);
    if (clipboard?.write && Item) {
        try {
            const blob = textPromise.then((value) => new Blob([value], { type: 'text/plain' }));
            await clipboard.write([new Item({ 'text/plain': blob })]);
            return;
        } catch {
            // Rethrows when the text failed to load. Otherwise try writeText for
            // browsers that do not accept a Promise in ClipboardItem.
            await textPromise;
        }
    }
    await clipboard.writeText(await textPromise);
}

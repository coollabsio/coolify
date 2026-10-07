/**
 * Splits the submitAction of the modal-confirmation component, such as
 * `replaceManagedDnsRecord('app.example.com', 4)`, into the Livewire method and its arguments.
 * Quoted arguments ('…' or "…") lose their quotes; other arguments stay text, as before.
 */
export function parseSubmitAction(submitAction) {
    const open = submitAction.indexOf('(');
    if (open === -1) {
        return { method: submitAction.trim(), params: [] };
    }

    const close = submitAction.lastIndexOf(')');
    const argumentText = submitAction.slice(open + 1, close > open ? close : undefined);
    const params = [];
    let current = '';
    let quote = null;
    let quoted = false;

    const finish = () => {
        const value = quoted ? current : current.trim();
        if (value !== '' || quoted) {
            params.push(value);
        }
        current = '';
        quoted = false;
    };

    for (let index = 0; index < argumentText.length; index++) {
        const character = argumentText[index];
        if (quote) {
            if (character === '\\' && index + 1 < argumentText.length) {
                current += argumentText[++index];
            } else if (character === quote) {
                quote = null;
            } else {
                current += character;
            }
        } else if ((character === "'" || character === '"') && current.trim() === '') {
            quote = character;
            quoted = true;
            current = '';
        } else if (character === ',') {
            finish();
        } else if (!quoted) {
            current += character;
        }
    }
    finish();

    return { method: submitAction.slice(0, open).trim(), params };
}

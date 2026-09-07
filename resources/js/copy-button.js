// Alpine data provider for the <x-copy-button> component (x-data="copyButton").
export function initializeCopyButtonComponent() {
    window.Alpine.data('copyButton', () => ({
        copied: false,
        async copy(value) {
            if (value === null || value === undefined) {
                window.toast('Value is not available.', { type: 'warning' });
                return;
            }
            try {
                if (navigator.clipboard?.writeText && window.isSecureContext) {
                    await navigator.clipboard.writeText(value);
                } else {
                    // Deprecated, but the only copy path on plain http (non-secure contexts).
                    const textarea = document.createElement('textarea');
                    textarea.value = value;
                    textarea.setAttribute('readonly', '');
                    textarea.style.position = 'fixed';
                    textarea.style.left = '-9999px';
                    document.body.appendChild(textarea);
                    textarea.select();
                    const ok = document.execCommand('copy');
                    document.body.removeChild(textarea);
                    if (!ok) {
                        throw new Error('Copy command was rejected.');
                    }
                }
                this.copied = true;
                setTimeout(() => (this.copied = false), 1200);
            } catch (e) {
                window.toast('Could not copy to clipboard.', { type: 'warning' });
            }
        },
    }));
}

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
                    // The textarea goes next to the button, not into <body>: a modal's x-trap
                    // pulls focus back from nodes outside it, which clears the selection.
                    const previousFocus = document.activeElement;
                    const textarea = document.createElement('textarea');
                    textarea.value = value;
                    textarea.setAttribute('readonly', '');
                    textarea.setAttribute('tabindex', '-1');
                    textarea.style.position = 'fixed';
                    textarea.style.left = '-9999px';
                    this.$el.after(textarea);
                    textarea.focus({ preventScroll: true });
                    textarea.select();
                    textarea.setSelectionRange(0, textarea.value.length);
                    const ok = document.execCommand('copy');
                    textarea.remove();
                    previousFocus?.focus?.({ preventScroll: true });
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

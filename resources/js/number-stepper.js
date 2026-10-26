// Alpine data provider for the −/+ buttons of <x-forms.input type="number"> (x-data="numberStepper").

/**
 * Returns the input value one step up (direction 1) or down (direction -1), clamped to min/max.
 * Uses the same strings the input element exposes: an empty or "any" step counts as 1.
 */
export function nextStepValue({ value, step, min, max }, direction) {
    const stepSize = Number.parseFloat(step) > 0 ? Number.parseFloat(step) : 1;
    const current = Number.parseFloat(value);
    let next = (Number.isFinite(current) ? current : 0) + direction * stepSize;

    if (min !== '' && Number.isFinite(Number(min))) {
        next = Math.max(next, Number(min));
    }
    if (max !== '' && Number.isFinite(Number(max))) {
        next = Math.min(next, Number(max));
    }

    // Remove floating point noise such as 0.30000000000000004.
    return String(Number.parseFloat(next.toFixed(10)));
}

export function initializeNumberStepperComponent() {
    window.Alpine.data('numberStepper', () => ({
        step(direction) {
            const input = this.$root.querySelector('input');
            if (input.disabled || input.readOnly) {
                return;
            }
            input.value = nextStepValue(input, direction);
            // wire:model and x-model listen to these events.
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        },
    }));
}

@props([
    'enabled',
    'enabledProperty',
    'toggleMethod',
    'testMethod' => 'sendTestNotification',
    'canUpdate' => true,
    'canResource' => null,
    'canGate' => 'update',
])

<div class="flex items-center gap-2"
    x-data="{
        enabled: @js((bool) $enabled),
        enabledProperty: @js($enabledProperty),
        toggleMethod: @js($toggleMethod),
        testMethod: @js($testMethod),
    }">
    <x-forms.button type="button" :disabled="!$canUpdate" :canGate="$canResource ? $canGate : null"
        :canResource="$canResource"
        x-bind:class="{ 'button-highlighted': !enabled }"
        x-on:click="
            if (!enabled && !$el.closest('form').reportValidity()) return;
            const next = !enabled;
            enabled = next;
            $wire.$set(enabledProperty, next)
                .then(() => $wire.$call(toggleMethod))
                .catch(() => { enabled = !next; });
        ">
        <span x-text="enabled ? 'Disable' : 'Enable'">{{ $enabled ? 'Disable' : 'Enable' }}</span>
    </x-forms.button>
    <x-forms.button type="button" :disabled="!$enabled" :canGate="$canResource ? 'sendTest' : null"
        :canResource="$canResource"
        x-on:click="if ($el.closest('form').reportValidity()) $wire.$call(testMethod)">
        <x-reicon name="notifications" class="size-3.5" />
        Send test
    </x-forms.button>
</div>

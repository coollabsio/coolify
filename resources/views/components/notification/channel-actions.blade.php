@props([
    'enabled',
    'enabledProperty',
    'toggleMethod',
    'testMethod' => 'sendTestNotification',
    'canUpdate' => true,
])

<div class="flex items-center gap-2"
    x-data="{
        enabled: @js((bool) $enabled),
        enabledProperty: @js($enabledProperty),
        toggleMethod: @js($toggleMethod),
        testMethod: @js($testMethod),
    }">
    <x-forms.button type="button" :disabled="!$canUpdate" :isHighlighted="!$enabled"
        x-on:click="
            if (!enabled && !$el.closest('form').reportValidity()) return;
            $wire.$set(enabledProperty, !enabled).then(() => $wire.$call(toggleMethod));
        ">
        {{ $enabled ? 'Disable' : 'Enable' }}
    </x-forms.button>
    <x-forms.button type="button" :disabled="!$enabled"
        x-on:click="if ($el.closest('form').reportValidity()) $wire.$call(testMethod)">
        <x-reicon name="notifications" class="size-3.5" />
        Send test
    </x-forms.button>
</div>

@if ($authDisabled || filled($tooltip))
<span class="relative inline-flex"
    x-data="{ visible: false, _t: null }"
    @mouseenter="_t = setTimeout(() => {
        visible = true;
        $nextTick(() => requestAnimationFrame(() => {
            const tip = $refs.tip;
            if (!tip) return;
            const r = $el.getBoundingClientRect();
            const t = tip.getBoundingClientRect();
            let top = r.top - t.height - 6;
            let left = r.left;
            if (top < 4) top = r.bottom + 6;
            if (left + t.width > innerWidth - 8) left = innerWidth - 8 - t.width;
            if (left < 4) left = 4;
            tip.style.top = top + 'px';
            tip.style.left = left + 'px';
        }));
    }, 300)"
    @mouseleave="clearTimeout(_t); visible = false">
@endif
<button @disabled($disabled) {{ $attributes->merge(['class' => $defaultClass]) }}
    {{ $attributes->merge(['type' => 'button']) }}
    @isset($confirm)
            x-on:click="toggleConfirmModal('{{ $confirm }}', '{{ explode('(', $confirmAction)[0] }}')"
        @endisset
    @isset($confirmAction)
            x-on:{{ explode('(', $confirmAction)[0] }}.window="$wire.{{ explode('(', $confirmAction)[0] }}"
        @endisset>

    {{ $slot }}
    @if ($showLoadingIndicator)
        @if ($attributes->whereStartsWith('wire:click')->first())
            <x-loading-on-button wire:target="{{ $attributes->whereStartsWith('wire:click')->first() }}"
                wire:loading.delay />
        @elseif($attributes->whereStartsWith('wire:target')->first())
            <x-loading-on-button wire:target="{{ $attributes->whereStartsWith('wire:target')->first() }}"
                wire:loading.delay />
        @endif
    @endif
</button>
@if ($authDisabled || filled($tooltip))
    <div x-ref="tip" x-show="visible" x-cloak class="auth-tooltip">
        {{ $tooltip ?: 'You do not have permission to perform this action.' }}
    </div>
</span>
@endif

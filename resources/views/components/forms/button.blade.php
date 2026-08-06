@php
    $wireClickValue = $attributes->whereStartsWith('wire:click')->first();
    $wireTargetValue = $attributes->get('wire:target');
    $hasExplicitWireTarget = $attributes->has('wire:target')
        && filled($wireTargetValue)
        && $wireTargetValue !== true
        && $wireTargetValue !== '1';

    $loadingTarget = null;
    if ($showLoadingIndicator) {
        if (filled($wireClickValue)) {
            $loadingTarget = trim((string) $wireClickValue);
        } elseif ($hasExplicitWireTarget) {
            $loadingTarget = trim((string) $wireTargetValue);
        }
    }

    $loadingAttributes = [];
    if (filled($loadingTarget)) {
        $loadingAttributes['wire:loading.attr'] = 'disabled';
        $loadingAttributes['wire:loading.class'] = 'is-loading';
        if (! $hasExplicitWireTarget) {
            $loadingAttributes['wire:target'] = $loadingTarget;
        }
    }
@endphp
@if ($authDisabled || filled($tooltip))
<span class="relative inline-flex"
    x-data="{
        visible: false,
        _t: null,
        showTooltip(delay = 0) {
            clearTimeout(this._t);
            this._t = setTimeout(() => {
                this.visible = true;
                this.positionTooltip();
            }, delay);
        },
        hideTooltip() {
            clearTimeout(this._t);
            this.visible = false;
        },
        positionTooltip() {
            this.$nextTick(() => requestAnimationFrame(() => {
                const tip = this.$refs.tip;
                if (!tip) return;
                const r = this.$el.getBoundingClientRect();
                const t = tip.getBoundingClientRect();
                let top = r.top - t.height - 6;
                let left = r.left;
                if (top < 4) top = r.bottom + 6;
                if (left + t.width > innerWidth - 8) left = innerWidth - 8 - t.width;
                if (left < 4) left = 4;
                tip.style.top = top + 'px';
                tip.style.left = left + 'px';
            }));
        }
    }"
    @if ($disabled) tabindex="0" :aria-describedby="visible ? $id('button-tooltip') : null" @endif
    @mouseenter="showTooltip(300)"
    @mouseleave="hideTooltip()"
    @focusin="showTooltip()"
    @focusout="hideTooltip()"
    @click.outside="hideTooltip()">
@endif
<button @disabled($disabled) @if ($isHighlighted) isHighlighted @endif
    @if ($authDisabled || filled($tooltip)) :aria-describedby="visible ? $id('button-tooltip') : null" @endif
    {{ $attributes->merge(['class' => $defaultClass, 'type' => 'button'])->merge($loadingAttributes) }}
    @isset($confirm)
            x-on:click="toggleConfirmModal('{{ $confirm }}', '{{ explode('(', $confirmAction)[0] }}')"
        @endisset
    @isset($confirmAction)
            x-on:{{ explode('(', $confirmAction)[0] }}.window="$wire.{{ explode('(', $confirmAction)[0] }}"
        @endisset>

    @if (filled($loadingTarget))
        <x-loading-on-button wire:target="{{ $loadingTarget }}" wire:loading />
    @endif
    {{ $slot }}
</button>
@if ($authDisabled || filled($tooltip))
    <div x-ref="tip" x-show="visible" x-cloak :id="$id('button-tooltip')" role="tooltip"
        class="auth-tooltip">
        {{ $tooltip ?: 'You do not have permission to perform this action.' }}
    </div>
</span>
@endif

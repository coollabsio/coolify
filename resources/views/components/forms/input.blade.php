<div @class([
    'flex-1' => $isMultiline,
    'w-full' => !$isMultiline,
])>
    @if ($label)
        {{--
            Fixed-height label row (h-4 = helper icon). Helper lives outside <label> so
            side-by-side fields align whether a field has a helper, required mark, both, or neither.
            Margin is only on this wrapper — mb-0! beats .application-settings-form label rules.
        --}}
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label class="mb-0! flex items-center gap-1 text-sm font-medium leading-4">{{ $label }}
                @if ($required)
                    <x-highlighted text="*" />
                @endif
                @isset($labelSuffix)
                    {{ $labelSuffix }}
                @endisset
            </label>
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </div>
    @endif
    @if ($loading)
        <div class="{{ $defaultClass }} flex w-full items-center text-neutral-500 dark:text-fg-dim" aria-busy="true">
            <x-loading :text="$loadingText" />
        </div>
    @elseif ($type === 'password')
        <div class="relative" x-data="{ type: 'password' }" @success.window="type = 'password'">
            <input autocomplete="{{ $autocomplete }}" value="{{ $value }}"
                x-bind:type="type"
                x-bind:class="{ 'truncate': type === 'text' && ! $el.disabled }"
                {{ $attributes->merge(['class' => $defaultClass]) }} @required($required)
                @if ($modelBinding !== 'null') wire:model={{ $modelBinding }} wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]" @endif
                wire:loading.attr="disabled"
                @readonly($readonly) @disabled($disabled) id="{{ $htmlId }}"
                name="{{ $name }}" placeholder="{{ $attributes->get('placeholder') }}"
                aria-placeholder="{{ $attributes->get('placeholder') }}"
                @if ($autofocus) x-ref="autofocusInput" @endif>
            @if ($allowToPeak)
                <button type="button" x-on:click="type = type === 'password' ? 'text' : 'password'"
                    class="password-toggle flex absolute inset-y-0 right-0 z-10 items-center pr-2 cursor-pointer text-neutral-500 hover:text-black dark:text-neutral-400 dark:hover:text-white"
                    aria-label="Toggle password visibility">
                    {{-- Eye icon (shown when password is hidden) --}}
                    <x-reicon name="eye" x-show="type === 'password'" class="size-[18px]" />
                    {{-- Eye-off2 icon (shown when password is visible) --}}
                    <x-reicon name="eye-off2" x-cloak x-show="type === 'text'" class="size-[18px]" />
                </button>
            @endif

        </div>
    @else
        <input autocomplete="{{ $autocomplete }}" @if ($value) value="{{ $value }}" @endif
            {{ $attributes->merge(['class' => $defaultClass]) }} @required($required) @readonly($readonly)
            @if ($modelBinding !== 'null') wire:model={{ $modelBinding }} wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]" @endif
            wire:loading.attr="disabled"
            type="{{ $type }}" @disabled($disabled) min="{{ $attributes->get('min') }}"
            max="{{ $attributes->get('max') }}" minlength="{{ $attributes->get('minlength') }}"
            maxlength="{{ $attributes->get('maxlength') }}"
            @if ($htmlId !== 'null') id={{ $htmlId }} @endif name="{{ $name }}"
            placeholder="{{ $attributes->get('placeholder') }}"
            @if ($autofocus) x-ref="autofocusInput" @endif>
    @endif
    @if (!$label && $helper)
        <x-helper :helper="$helper" />
    @endif
    @error($modelBinding)
        <label class="label">
            @php
                preg_match('/(https?:\/\/\S+)$/', $message, $validationLinkMatches);
                $validationLink = $validationLinkMatches[1] ?? null;
            @endphp
            <span class="text-red-500 label-text-alt">
                @if ($validationLink)
                    {{ str($message)->beforeLast($validationLink)->trim() }}
                    <a class="font-medium underline" href="{{ $validationLink }}">Set them here.</a>
                @else
                    {{ $message }}
                @endif
            </span>
        </label>
    @enderror
</div>

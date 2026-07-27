<div @class([
    'flex-1' => $isMultiline,
    'w-full' => !$isMultiline,
])>
    @if ($label)
        <label class="flex gap-1 items-center mb-1 text-sm font-medium">{{ $label }}
            @if ($required)
                <x-highlighted text="*" />
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
            @isset($labelSuffix)
                {{ $labelSuffix }}
            @endisset
        </label>
    @endif
    @if ($type === 'password')
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
                    class="flex absolute inset-y-0 right-0 items-center pr-2 cursor-pointer dark:hover:text-white"
                    aria-label="Toggle password visibility">
                    {{-- Eye icon (shown when password is hidden) --}}
                    <x-reicon name="eye" x-show="type === 'password'" class="size-[18px]" />
                    {{-- Eye-off icon (shown when password is visible) --}}
                    <x-reicon name="eye-off" x-cloak x-show="type === 'text'" class="size-[18px]" />
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
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>

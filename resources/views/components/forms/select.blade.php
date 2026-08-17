<div class="w-full">
    @if ($label)
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label
                class="mb-0! flex items-center gap-1 text-sm font-medium leading-4 {{ $disabled ? 'text-neutral-600' : '' }}">{{ $label }}
                @if ($required)
                    <x-highlighted text="*" />
                @endif
            </label>
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </div>
    @endif
    <select {{ $attributes->merge(['class' => $defaultClass]) }} @disabled($disabled) @required($required)
        wire:loading.attr="disabled" name={{ $modelBinding }} id="{{ $htmlId }}"
        @if ($attributes->whereStartsWith('wire:model')->first()) {{ $attributes->whereStartsWith('wire:model')->first() }} wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]" @else wire:model={{ $modelBinding }} wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]" @endif>
        {{ $slot }}
    </select>
    @error($modelBinding)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>

<div class="px-2 form-control min-w-fit hover:bg-coolgray-100">
    <label class="flex gap-4 px-0 cursor-pointer label">
        <span class="flex gap-2 label-text min-w-fit">
            @if ($label)
                {{ $label }}
            @else
                {{ $id }}
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </span>
        <input @disabled($disabled) type="checkbox" {{ $attributes->merge(['class' => $defaultClass]) }}
            @if ($instantSave) wire:loading.attr="disabled" wire:click='{{ $instantSave === 'instantSave' || $instantSave == '1' ? 'instantSave' : $instantSave }}'
               wire:model.defer={{ $id }} @else wire:model.defer={{ $value ?? $id }} @endif />
    </label>
</div>

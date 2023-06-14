@props([
    'id' => $attributes->has('id') || $attributes->has('label'),
    'required' => $attributes->has('required'),
    'label' => $attributes->has('label'),
    'helper' => $attributes->has('helper'),
    'instantSave' => $attributes->has('instantSave'),
    'noLabel' => $attributes->has('noLabel'),
    'noDirty' => $attributes->has('noDirty'),
    'disabled' => null,
])
<div {{ $attributes->merge(['class' => 'flex cursor-pointer w-96 label']) }}>
    <div class="flex gap-1 label-text">
        @if ($label)
            {{ $label }}
        @else
            {{ $id }}
        @endif
        @if ($helper)
            <div class="group w-fit">
                <div class="cursor-pointer text-warning">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                        class="w-4 h-4 stroke-current">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="absolute hidden text-xs group-hover:block border-coolgray-400 bg-coolgray-500">
                    <div class="p-4 card-body">
                        {!! $helper !!}
                    </div>
                </div>
            </div>
        @endif
    </div>
    <div class="flex-1"></div>
    <input type="checkbox" @if ($disabled !== null) disabled @endif name={{ $id }}
        @if (!$noDirty) wire:dirty.class="input-warning" @endif
        @if ($instantSave) wire:click='instantSave' wire:model.defer={{ $id }} @else wire:model.defer={{ $value ?? $id }} @endif />
</div>

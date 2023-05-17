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

<label {{ $attributes->merge(['class' => 'flex items-center gap-16 cursor-pointer']) }}>
    <span class="label-text">
        @if ($label)
            {{ $label }}
        @else
            {{ $id }}
        @endif
    </span>
    <input type="checkbox" @if ($disabled !== null) disabled @endif class="toggle" name={{ $id }}
        @if (!$noDirty) wire:dirty.class="input-warning" @endif
        @if ($instantSave) wire:click='instantSave' wire:model.defer={{ $id }} @else wire:model.defer={{ $value ?? $id }} @endif />
</label>
</div>

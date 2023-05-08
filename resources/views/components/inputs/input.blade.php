@props([
    'id' => $attributes->has('id') || $attributes->has('label'),
    'type' => 'text',
    'required' => $attributes->has('required'),
    'label' => $attributes->has('label'),
    'instantSave' => $attributes->has('instantSave'),
    'noLabel' => $attributes->has('noLabel'),
    'noDirty' => $attributes->has('noDirty'),
])

<span @class([
    'flex' => $type === 'checkbox',
    'flex flex-col' => $type !== 'checkbox',
])>
    @if (!$noLabel)
        <label for={{ $id }} @if (!$noDirty) wire:dirty.class="text-amber-300" @endif
            wire:target={{ $id }}>
            @if ($label)
                {{ $label }}
            @else
                {{ $id }}
            @endif
            @if ($required)
                *
            @endif
        </label>
    @endif
    @if ($type === 'textarea')
        <textarea @if (!$noDirty) wire:dirty.class="text-black bg-amber-300" @endif {{ $attributes }}
            required={{ $required }} type={{ $type }} id={{ $id }} wire:model.defer={{ $id }}></textarea>
    @else
        <input {{ $attributes }} @if ($required) required @endif
            @if (!$noDirty) wire:dirty.class="text-black bg-amber-300" @endif
            type={{ $type }} id={{ $id }}
            @if ($instantSave) wire:click='instantSave' wire:model.defer={{ $id }} @else wire:model.defer={{ $value ?? $id }} @endif />
    @endif
    @error($id)
        <div class="text-red-500">{{ $message }}</div>
    @enderror
</span>

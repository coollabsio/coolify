@props([
    'id' => null,
    'type' => 'text',
    'required' => $attributes->has('required'),
    'label' => null,
    'instantSave' => false,
    'disabled' => false,
    'hidden' => false,
    'noLabel' => false,
    'noDirty' => false,
])

<span @class([
    'flex justify-end' => $type === 'checkbox',
    'flex flex-col' => $type !== 'checkbox',
])>
    @if (!$noLabel)
        <label for={{ $id }}>
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
        <input {{ $attributes }} required={{ $required }}
            @if (!$noDirty) wire:dirty.class="text-black bg-amber-300" @endif
            type={{ $type }} id={{ $id }}
            @if ($instantSave) wire:click='instantSave' wire:model.defer={{ $id }} @else wire:model.defer={{ $value ?? $id }} @endif />
    @endif
    @error($id)
        <div class="text-red-500">{{ $message }}</div>
    @enderror
</span>

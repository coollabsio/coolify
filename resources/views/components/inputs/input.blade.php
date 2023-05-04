@props([
    'id' => null,
    'type' => 'text',
    'required' => false,
    'label' => null,
    'instantSave' => false,
    'disabled' => false,
    'hidden' => false,
])


<span @class([
    'flex justify-end' => $type === 'checkbox',
    'flex flex-col' => $type !== 'checkbox',
])>
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
    @if ($type === 'textarea')
        <textarea {{ $attributes }} type={{ $type }} id={{ $id }} wire:model.defer={{ $id }}></textarea>
    @else
        <input {{ $attributes }} type={{ $type }} id={{ $id }}
            @if ($instantSave) wire:click='instantSave' wire:model.defer={{ $id }} @else wire:model.defer={{ $id }} @endif />
    @endif

    @error($id)
        <div class="text-red-500">{{ $message }}</div>
    @enderror
</span>

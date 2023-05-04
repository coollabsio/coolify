@props([
    'id' => null,
    'label' => null,
    'required' => false,
])

<span @class(['flex flex-col'])>
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
    <select {{ $attributes }} wire:model.defer={{ $id }}>
        {{ $slot }}
    </select>

    @error($id)
        <div class="text-red-500">{{ $message }}</div>
    @enderror
</span>

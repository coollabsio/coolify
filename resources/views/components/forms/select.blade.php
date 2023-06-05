@props([
    'id' => null,
    'label' => null,
    'required' => false,
])

<span {{ $attributes->merge(['class' => 'flex flex-col']) }}>
    <label class="label" for={{ $id }}>
        <span class="label-text">
            @if ($label)
                {{ $label }}
            @else
                {{ $id }}
            @endif
            @if ($required)
                <span class="text-warning">*</span>
            @endif
        </span>
    </label>
    <select {{ $attributes }} wire:model.defer={{ $id }}>
        {{ $slot }}
    </select>

    @error($id)
        <div class="text-red-500">{{ $message }}</div>
    @enderror
</span>

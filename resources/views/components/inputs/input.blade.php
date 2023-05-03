@props([
    'id' => null,
    'required' => false,
    'readonly' => false,
    'label' => null,
    'type' => 'text',
    'class' => '',
    'instantSave' => false,
    'disabled' => false,
    'hidden' => false,
])

@if ($type === 'checkbox')
    <label for={{ $id }}>
        @if ($label)
            {{ $label }}
        @else
            {{ $id }}
        @endif
        @if ($required)
            *
        @endif
        <input type="checkbox" id={{ $id }}
            @if ($instantSave) wire:click='instantSave' wire:model.defer={{ $id }} @else wire:model.defer={{ $id }} @endif>
    </label>
    @error($id)
        <span class="text-red-500">{{ $message }}</span>
    @enderror
@else
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
        <textarea class={{ $class }} type={{ $type }} id={{ $id }}
            wire:model.defer={{ $id }} @if ($required) required @endif
            @if ($disabled) disabled @endif @if ($readonly) readOnly disabled @endif></textarea>
    @else
        <input class={{ $class }} type={{ $type }} id={{ $id }}
            wire:model.defer={{ $id }} @if ($required) required @endif
            @if ($disabled) disabled @endif
            @if ($readonly) readOnly disabled @endif />
    @endif

    @error($id)
        <div class="text-red-500">{{ $message }}</div>
    @enderror
@endif

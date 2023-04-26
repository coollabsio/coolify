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
    <input type={{ $type }} id={{ $id }} wire:model.defer={{ $id }}
        @if ($required) required @endif @if ($disabled) disabled @endif
        @if ($readonly) readOnly disabled @endif />
    @error($id)
        <div class="text-red-500">{{ $message }}</div>
    @enderror
@endif

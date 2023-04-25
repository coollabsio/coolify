@if ($type === 'checkbox')
    <label for={{ $name }}>
        @if ($label)
            {{ $label }}
        @else
            {{ $name }}
        @endif
        @if ($required)
            *
        @endif
        <input type="checkbox" id={{ $name }}
            @if ($instantSave) wire:click='instantSave' wire:model.defer={{ $name }} @else wire:model.defer={{ $name }} @endif
            name={{ $name }}>
    </label>
@else
    <label for={{ $name }}>
        @if ($label)
            {{ $label }}
        @else
            {{ $name }}
        @endif
        @if ($required)
            *
        @endif
    </label>
    <input type={{ $type }} id={{ $name }} wire:model.defer={{ $name }}
        name={{ $name }} @if ($required) required @endif
        @if ($readonly) readOnly=true disabled=true @endif />
@endif

@error($name)
    <span class="text-red-500">{{ $message }}</span>
@enderror

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
<input id={{ $name }} wire:model.defer={{ $name }} type="text" name={{ $name }}
    @if ($required) required @endif
    @if ($readonly) readOnly=true disabled=true @endif />
@error($name)
    <span class="text-red-500">{{ $message }}</span>
@enderror

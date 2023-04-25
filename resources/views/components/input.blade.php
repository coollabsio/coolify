<label for={{ $id }}>
    @if ($label)
        {{ $label }}
    @else
        {{ $id }}
    @endif
    @if ($required)
        *
    @endif
    <input id={{ $id }} type={{ $type }} wire:model.defer={{ $id }}>
</label>

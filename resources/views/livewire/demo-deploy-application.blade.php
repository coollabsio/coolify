<div>
    @isset($activity?->id)
        <div>
            Activity: <span>{{ $activity?->id ?? 'waiting' }}</span>
        </div>
        <pre style="width: 100%;overflow-y: scroll;" @if ($isKeepAliveOn) wire:poll.750ms="polling" @endif>{{ data_get($activity, 'description') }}</pre>
        {{-- <div>
        <div>Details:</div>
        <pre style="width: 100%;overflow-y: scroll;">{{ json_encode(data_get($activity, 'properties'), JSON_PRETTY_PRINT) }}</pre>
    </div> --}}
    @endisset
    <button wire:click='deploy'>Deploy</button>
</div>

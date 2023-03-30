<div>
    @isset($activity?->id)
        <div>
            Activity: <span>{{ $activity?->id ?? 'waiting' }}</span>
        </div>
        <pre style="width: 100%;overflow-y: scroll;" @if ($isKeepAliveOn) wire:poll.750ms="polling" @endif>{{ data_get($activity, 'description') }}</pre>
    @endisset
    <button wire:click='deploy'>Deploy</button>
</div>

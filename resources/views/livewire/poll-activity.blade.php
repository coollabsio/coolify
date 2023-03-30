<div>
    @isset($activity?->id)
        <pre style="width: 100%;overflow-y: scroll;" @if ($isKeepAliveOn) wire:poll.750ms="polling" @endif>{{ data_get($activity, 'description') ?? 'Logs will be here soon...' }}</pre>
    @endisset
</div>

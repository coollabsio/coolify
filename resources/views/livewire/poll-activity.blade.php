<div>
    <pre style="width: 100%;overflow-y: scroll;" @if ($isKeepAliveOn) wire:poll.3750ms="polling" @endif>
        {{ \App\Actions\RemoteProcess\RunRemoteProcess::decodeOutput($activity) }}
    </pre>
</div>

<div>
    <pre
        style="width: 100%;overflow-y: scroll;"
        @if ($isKeepAliveOn) wire:poll.750ms="polling" @endif
    >{{ \App\Actions\CoolifyTask\RunRemoteProcess::decodeOutput($activity) }}</pre>
</div>

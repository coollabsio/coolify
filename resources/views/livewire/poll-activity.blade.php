<div>
    <pre style="width: 100%;overflow-y: scroll;" @if ($isKeepAliveOn) wire:poll.750ms="polling" @endif>
        @isset($activity)
        {{ (new App\Actions\RemoteProcess\TidyOutput($activity))() }}
        @endisset
    </pre>
</div>

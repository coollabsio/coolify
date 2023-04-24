<div>
    @if ($application->status === 'running')
        <button wire:click='start'>Restart</button>
        <button wire:click='forceRebuild'>Force Rebuild</button>
        <button wire:click='stop'>Stop</button>
    @else
        <button wire:click='start'>Start</button>
        <button wire:click='forceRebuild'>Start (no cache)</button>
    @endif
    <button wire:click='kill'>Kill</button>
    <span wire:poll='pollingStatus'>
        @if ($application->status === 'running')
            <span class="text-green-500">{{ $application->status }}</span>
            @if (!data_get($application, 'settings.is_bot') && data_get($application, 'fqdn'))
                <a target="_blank" href="{{ data_get($application, 'fqdn') }}">Open URL</a>
            @endif

            @if (data_get($application, 'ports_exposes_array'))
                @foreach ($application->ports_exposes_array as $port)
                    @if (env('APP_ENV') === 'local')
                        <a target="_blank" href="http://localhost:{{ $port }}">Open
                            {{ $port }}</a>
                    @else
                        <a target="_blank"
                            href="http://{{ $application->destination->server->ip }}:{{ $port }}">Open
                            {{ $port }}</a>
                    @endif
                @endforeach
            @endif
        @else
            <span class="text-red-500">{{ $application->status }}</span>
        @endif
    </span>
</div>

<div>
    <x-slot:title>
        Destinations | Coolify
    </x-slot>
    <div class="form-section-title mb-6">
        <h1>Destinations</h1>
        <div class="flex items-center gap-2">
            @if ($servers->count() > 0)
                @can('createAnyResource')
                    <x-modal-input buttonTitle="+ Add" title="New Destination">
                        <livewire:destination.new.docker />
                    </x-modal-input>
                @endcan
            @endif
        </div>
    </div>
    <p class="text-sm text-neutral-500 dark:text-neutral-400 -mt-4 mb-4">Network endpoints to deploy your resources.</p>
    <div class="grid gap-4 lg:grid-cols-2 -mt-1">
        @forelse ($servers as $server)
            @forelse ($server->destinations() as $destination)
                @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                    <a class="coolbox group" {{ wireNavigate() }}
                        href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                        <div class="flex flex-col justify-center mx-6">
                            <div class="box-title">{{ $destination->name }}</div>
                            <div class="box-description">Server: {{ $destination->server->name }}</div>
                        </div>
                    </a>
                @endif
                @if ($destination->getMorphClass() === 'App\Models\SwarmDocker')
                    <a class="coolbox group" {{ wireNavigate() }}
                        href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                        <div class="flex flex-col mx-6">
                            <div class="box-title">{{ $destination->name }}</div>
                            <div class="box-description">server: {{ $destination->server->name }}</div>
                        </div>
                    </a>
                @endif
            @empty
                <div class="empty-state">No destinations found.</div>
            @endforelse
        @empty
            <div class="empty-state">No servers found.</div>
        @endforelse
    </div>
</div>

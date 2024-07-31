<x-layout>
    <x-slot:title>
        Destinations | Coolify
    </x-slot>
    <div class="flex items-start gap-2">
        <h1>Destinations</h1>
        @if ($servers->count() > 0)
            <x-modal-input buttonTitle="+ Add" title="New Destination">
                <livewire:destination.new.docker :server_id="$server_id" />
            </x-modal-input>
        @endif
    </div>
    <div class="subtitle">Network endpoints to deploy your resources.</div>
    <div class="grid gap-2 lg:grid-cols-1">
        @forelse ($destinations as $destination)
            @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                <a class="box group"
                    href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                    <div class="flex flex-col mx-6">
                        <div class="box-title">{{ $destination->name }}</div>
                        <div class="box-description">server: {{ $destination->server->name }}</div>
                    </div>
                </a>
            @endif
            @if ($destination->getMorphClass() === 'App\Models\SwarmDocker')
                <a class="box group"
                    href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                    <div class="flex flex-col mx-6">
                        <div class="box-title">{{ $destination->name }}</div>
                        <div class="box-description">server: {{ $destination->server->name }}</div>
                    </div>
                </a>
            @endif
        @empty
            <div>
                @if ($servers->count() === 0)
                    <div>No servers found. Please add one first.</div>
                @else
                    <div>No destinations found.</div>
                @endif
            </div>
        @endforelse
    </div>
</x-layout>

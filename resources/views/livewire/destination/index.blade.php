<div>
    <x-slot:title>
        {{ __('destinations.title') }}
    </x-slot>
    <div class="flex items-center gap-2">
        <h1>{{ __('destinations.heading') }}</h1>
        @if ($servers->count() > 0)
            @can('createAnyResource')
                <x-modal-input buttonTitle="{{ __('button.add') }}" title="{{ __('modal.new_destination') }}">
                    <livewire:destination.new.docker />
                </x-modal-input>
            @endcan
        @endif
    </div>
    <div class="subtitle">{{ __('destinations.subtitle') }}</div>
    <div class="grid gap-4 lg:grid-cols-2 -mt-1">
        @forelse ($servers as $server)
            @forelse ($server->destinations() as $destination)
                @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                    <a class="coolbox group" {{ wireNavigate() }}
                        href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                        <div class="flex flex-col justify-center mx-6">
                            <div class="box-title">{{ $destination->name }}</div>
                            <div class="box-description">{{ __('destinations.server_label') }}: {{ $destination->server->name }}</div>
                        </div>
                    </a>
                @endif
                @if ($destination->getMorphClass() === 'App\Models\SwarmDocker')
                    <a class="coolbox group" {{ wireNavigate() }}
                        href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                        <div class="flex flex-col mx-6">
                            <div class="box-title">{{ $destination->name }}</div>
                            <div class="box-description">{{ __('destinations.server_label') }}: {{ $destination->server->name }}</div>
                        </div>
                    </a>
                @endif
            @empty
                <div>{{ __('destinations.no_destinations') }}</div>
            @endforelse
        @empty
            <div>{{ __('dashboard.no_servers') }}</div>
        @endforelse
    </div>
</div>

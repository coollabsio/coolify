<x-layout>
    <div class="flex items-start gap-2">
        <h1>Destinations</h1>
        <x-slide-over fullScreen closeWithX>
            <x-slot:title>New Destination</x-slot:title>
            <x-slot:content>
                <livewire:destination.new.docker :server_id="$server_id" />
            </x-slot:content>
            <button @click="slideOverOpen=true" class="button">+
                Add</button>
        </x-slide-over>
    </div>
    <div class="subtitle">Endpoints to deploy your resources.</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($destinations as $destination)
            @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                <a class="flex gap-4 text-center hover:no-underline box group"
                    href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                    <div class="group-hover:text-white">
                        <div>{{ $destination->name }}</div>
                    </div>
                </a>
            @endif
            @if ($destination->getMorphClass() === 'App\Models\SwarmDocker')
                <a class="flex gap-4 text-center hover:no-underline box group"
                    href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">

                    <div class="group-hover:text-white">
                        <div>{{ $destination->name }}</div>
                    </div>
                </a>
            @endif
        @empty
            <div>
                <div>No destinations found.</div>
            </div>
        @endforelse
    </div>
</x-layout>

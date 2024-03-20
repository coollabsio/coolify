<x-layout>
    <h1>Destinations</h1>
    <div class="subtitle ">All Destinations.</div>
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
                <x-use-magic-bar />
            </div>
        @endforelse
    </div>
</x-layout>

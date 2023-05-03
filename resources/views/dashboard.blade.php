<x-layout>
    <h1>Projects <a href="{{ route('project.new') }}">
            <x-inputs.button>New</x-inputs.button>
        </a></h1>
    @forelse ($projects as $project)
        <a href="{{ route('project.environments', [$project->uuid]) }}">{{ data_get($project, 'name') }}</a>
    @empty
        <p>No projects found.</p>
    @endforelse
    <h1>Servers <a href="{{ route('server.new') }}">
            <x-inputs.button>New</x-inputs.button>
        </a></h1>
    @forelse ($servers as $server)
        <a href="{{ route('server.show', [$server->uuid]) }}">{{ data_get($server, 'name') }}</a>
    @empty
        <p>No servers found.</p>
    @endforelse
    <h1>Destinations <a href="{{ route('destination.new') }}">
            <x-inputs.button>New</x-inputs.button>
        </a></h1>
    @forelse ($destinations as $destination)
        <a href="{{ route('destination.show', [$destination->uuid]) }}">{{ data_get($destination, 'name') }}</a>
    @empty
        <p>No destinations found.</p>
    @endforelse
    <h1>Private Keys <a href="{{ route('private-key.new') }}">
            <x-inputs.button>New</x-inputs.button>
        </a></h1>
    @forelse ($private_keys as $private_key)
        <a href="{{ route('private-key.show', [$private_key->uuid]) }}">{{ data_get($private_key, 'name') }}</a>
    @empty
        <p>No servers found.</p>
    @endforelse
</x-layout>

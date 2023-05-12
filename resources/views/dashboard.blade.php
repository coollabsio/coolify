<x-layout>
    <h1>Projects </h1>
    @forelse ($projects as $project)
        <a href="{{ route('project.environments', [$project->uuid]) }}">{{ data_get($project, 'name') }}</a>
    @empty
        <p>No projects found.</p>
    @endforelse
    <h1>Servers </h1>
    @forelse ($servers as $server)
        <a href="{{ route('server.show', [$server->uuid]) }}">{{ data_get($server, 'name') }}</a>
    @empty
        <p>No servers found.</p>
    @endforelse
    <h1>Destinations </h1>
    @forelse ($destinations as $destination)
        <a href="{{ route('destination.show', [$destination->uuid]) }}">{{ data_get($destination, 'name') }}</a>
    @empty
        <p>No destinations found.</p>
    @endforelse
    <h1>Private Keys </h1>
    @forelse ($private_keys as $private_key)
        <a href="{{ route('private-key.show', [$private_key->uuid]) }}">{{ data_get($private_key, 'name') }}</a>
    @empty
        <p>No servers found.</p>
    @endforelse
    <h1>GitHub Apps </h1>
    @forelse ($github_apps as $github_app)
        <a href="{{ route('source.github.show', [$github_app->uuid]) }}">{{ data_get($github_app, 'name') }}</a>
    @empty
        <p>No servers found.</p>
    @endforelse
</x-layout>

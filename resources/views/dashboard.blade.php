<x-layout>
    @if ($servers->count() === 0)
        <div class="flex flex-col items-center justify-center h-full pt-32">
            <div class="">Without a server, you won't be able to do much...</div>
            <div>Let's create <a class="underline text-warning" href="{{ route('server.new') }}">your
                    first</a> one!</div>
        </div>
    @else
        <h1>Projects </h1>
        <div class="flex gap-2">
            @forelse ($projects as $project)
                <a href="{{ route('project.environments', [$project->uuid]) }}"
                    class="box">{{ data_get($project, 'name') }}</a>
            @empty
                <p>No projects found.</p>
            @endforelse
        </div>
        <h1>Servers </h1>
        <div class="flex gap-2">
            @forelse ($servers as $server)
                <a href="{{ route('server.show', [$server->uuid]) }}" class="box">{{ data_get($server, 'name') }}</a>
            @empty
                <p>No servers found.</p>
            @endforelse
        </div>
        {{-- <h1>Destinations </h1>
        <div class="flex gap-2">
            @forelse ($destinations as $destination)
                <a href="{{ route('destination.show', [$destination->uuid]) }}"
                    class="box">{{ data_get($destination, 'name') }}</a>
            @empty
                <p>No destinations found.</p>
            @endforelse
        </div> --}}
        {{-- <h1>Private Keys </h1>
        <div class="flex gap-2">
            @forelse ($private_keys as $private_key)
                <a href="{{ route('private-key.show', [$private_key->uuid]) }}"
                    class="box">{{ data_get($private_key, 'name') }}</a>
            @empty
                <p>No servers found.</p>
            @endforelse
        </div> --}}
        {{-- <h1>GitHub Apps </h1>
        <div class="flex">
            @forelse ($github_apps as $github_app)
                <a href="{{ route('source.github.show', [$github_app->uuid]) }}"
                    class="box">{{ data_get($github_app, 'name') }}</a>
            @empty
                <p>No servers found.</p>
            @endforelse
        </div> --}}
    @endif

</x-layout>

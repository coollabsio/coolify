<x-layout>
    <x-slot:title>
        Sources | Coolify
    </x-slot>
    <div class="flex items-center gap-2">
        <h1>Sources</h1>
        @can('createAnyResource')
            <x-modal-input buttonTitle="+ Add GitHub" title="New GitHub App" :closeOutside="false">
                <livewire:source.github.create />
            </x-modal-input>
            <x-modal-input buttonTitle="+ Add GitLab" title="New GitLab App" :closeOutside="false">
                <livewire:source.gitlab.create />
            </x-modal-input>
        @endcan
    </div>
    <div class="subtitle">Git sources for your applications.</div>
    <div class="grid gap-4 lg:grid-cols-2 -mt-1">
        @forelse ($sources as $source)
            @if ($source->getMorphClass() === 'App\Models\GithubApp')
                <a class="flex gap-2 text-center hover:no-underline coolbox group"
                    {{ wireNavigate() }}
                    href="{{ route('source.github.show', ['github_app_uuid' => data_get($source, 'uuid')]) }}">
                    <div class="text-left dark:group-hover:text-white flex flex-col justify-center mx-6">
                        <div class="box-title">
                            <x-git-icon class="inline-block w-4 h-4 mr-1" git="App\Models\GithubApp" />
                            {{ $source->name }}
                        </div>
                        @if ($source->isConnected())
                            <span class="box-description text-success">Connected</span>
                        @else
                            <span class="box-description text-warning">Setup required</span>
                        @endif
                    </div>
                </a>
            @elseif ($source->getMorphClass() === 'App\Models\GitlabApp')
                <a class="flex gap-2 text-center hover:no-underline coolbox group"
                    {{ wireNavigate() }}
                    href="{{ route('source.gitlab.show', ['gitlab_app_uuid' => data_get($source, 'uuid')]) }}">
                    <div class="text-left dark:group-hover:text-white flex flex-col justify-center mx-6">
                        <div class="box-title">
                            <x-git-icon class="inline-block w-4 h-4 mr-1" git="App\Models\GitlabApp" />
                            {{ $source->name }}
                        </div>
                        @if ($source->isConnected())
                            <span class="box-description text-success">Connected</span>
                        @else
                            <span class="box-description text-warning">Setup required</span>
                        @endif
                    </div>
                </a>
            @endif
        @empty
            <div>
                <div>No sources found.</div>
            </div>
        @endforelse
    </div>
</x-layout>

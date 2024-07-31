<x-layout>
    <x-slot:title>
        Sources | Coolify
    </x-slot>
    <div class="flex items-start gap-2">
        <h1>Sources</h1>
        <x-modal-input buttonTitle="+ Add" title="New GitHub App">
            <livewire:source.github.create />
        </x-modal-input>
    </div>
    <div class="subtitle ">Git sources for your applications.</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($sources as $source)
            @if ($source->getMorphClass() === 'App\Models\GithubApp')
                <a class="flex gap-4 text-center hover:no-underline box group"
                    href="{{ route('source.github.show', ['github_app_uuid' => data_get($source, 'uuid')]) }}">
                    <x-git-icon class="dark:text-white w-9 h-9" git="{{ $source->getMorphClass() }}" />
                    <div class="text-left group-hover:dark:text-white">
                        <div>{{ $source->name }}</div>
                        @if (is_null($source->app_id))
                            <span class="text-error">Configuration is not finished</span>
                        @endif
                    </div>
                </a>
            @endif
            @if ($source->getMorphClass() === 'App\Models\GitlabApp')
                <a class="flex gap-4 text-center hover:no-underline box group"
                    href="{{ route('source.gitlab.show', ['gitlab_app_uuid' => data_get($source, 'uuid')]) }}">
                    <x-git-icon class="dark:text-white w-9 h-9" git="{{ $source->getMorphClass() }}" />
                    <div class="text-left group-hover:dark:text-white">
                        <div>{{ $source->name }}</div>
                        @if (is_null($source->app_id))
                            <span class="text-error">Configuration is not finished</span>
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

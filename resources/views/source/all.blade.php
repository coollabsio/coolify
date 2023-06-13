<x-layout>
    <h1>Sources</h1>
    <div class="pt-2 pb-10 text-sm">All Sources</div>
    <div class="grid grid-cols-2 gap-2">
        @forelse ($sources as $source)
            @if ($source->getMorphClass() === 'App\Models\GithubApp')
                <a class="flex gap-4 text-center hover:no-underline box group"
                    href="{{ route('source.github.show', ['github_app_uuid' => data_get($source, 'uuid')]) }}">
                    <x-git-icon class="text-white w-9 h-9" git="{{ $source->getMorphClass() }}" />
                    <div class="group-hover:text-white">
                        <div>{{ $source->name }}</div>
                        @if (is_null($source->app_id))
                            <span class="text-error">Not registered</span>
                        @endif
                    </div>

                </a>
            @endif
            @if ($source->getMorphClass() === 'App\Models\GitlabApp')
                <a class="flex gap-4 text-center hover:no-underline box group"
                    href="{{ route('source.gitlab.show', ['gitlab_app_uuid' => data_get($source, 'uuid')]) }}">
                    <x-git-icon class="text-white w-9 h-9" git="{{ $source->getMorphClass() }}" />
                    <div class="group-hover:text-white">
                        <div>{{ $source->name }}</div>
                        @if (is_null($source->app_id))
                            <span class="text-error">Not registered</span>
                        @endif
                    </div>
                </a>
            @endif
        @empty
            <div>
                <div>No sources found.</div>
                <x-use-magic-bar />
            </div>
        @endforelse
    </div>
</x-layout>

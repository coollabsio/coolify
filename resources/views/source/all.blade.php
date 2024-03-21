<x-layout>
    <div class="flex items-start gap-2">
        <h1>Sources</h1>
        <x-slide-over fullScreen closeWithX>
            <x-slot:title>New GitHub App</x-slot:title>
            <x-slot:content>
                <livewire:source.github.create />
            </x-slot:content>
            <button @click="slideOverOpen=true" class="button">+
                Add</button>
        </x-slide-over>
    </div>
    <div class="subtitle ">All Sources.</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($sources as $source)
            @if ($source->getMorphClass() === 'App\Models\GithubApp')
                <a class="flex gap-4 text-center hover:no-underline box group"
                    href="{{ route('source.github.show', ['github_app_uuid' => data_get($source, 'uuid')]) }}">
                    <x-git-icon class="text-white w-9 h-9" git="{{ $source->getMorphClass() }}" />
                    <div class="text-left group-hover:text-white">
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
                    <x-git-icon class="text-white w-9 h-9" git="{{ $source->getMorphClass() }}" />
                    <div class="text-left group-hover:text-white">
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
                <x-use-magic-bar link="/source/new" />
            </div>
        @endforelse
    </div>
</x-layout>

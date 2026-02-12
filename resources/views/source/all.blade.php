<x-layout>
    <x-slot:title>
        Sources | Coolify
    </x-slot>
    <div class="form-section-title mb-6">
        <h1>Sources</h1>
        <div class="flex items-center gap-2">
            @can('createAnyResource')
                <x-modal-input buttonTitle="+ Add" title="New GitHub App" :closeOutside="false">
                    <livewire:source.github.create />
                </x-modal-input>
            @endcan
        </div>
    </div>
    <p class="text-sm text-neutral-500 dark:text-neutral-400 -mt-4 mb-4">Git sources for your applications.</p>
    <div class="grid gap-4 lg:grid-cols-2 -mt-1">
        @forelse ($sources as $source)
            @if ($source->getMorphClass() === 'App\Models\GithubApp')
                <a class="flex gap-2 text-center hover:no-underline coolbox group"
                    {{ wireNavigate() }}
                    href="{{ route('source.github.show', ['github_app_uuid' => data_get($source, 'uuid')]) }}">
                    {{-- <x-git-icon class="dark:text-white w-8 h-8 mt-1" git="{{ $source->getMorphClass() }}" /> --}}
                    <div class="text-left dark:group-hover:text-white flex flex-col justify-center mx-6">
                        <div class="box-title">{{ $source->name }}</div>
                        @if (is_null($source->app_id))
                            <span class="box-description text-error! ">Configuration is not finished.</span>
                        @else
                            @if ($source->organization)
                                <span class="box-description">Organization: {{ $source->organization }}</span>
                            @endif
                        @endif
                    </div>
                </a>
            @endif
        @empty
            <div class="empty-state">No sources found.</div>
        @endforelse
    </div>
</x-layout>

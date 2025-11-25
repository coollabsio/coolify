<div>
    <x-slot:title>
        GitHub Runner Sources | Coolify
    </x-slot>
    <div class="flex items-center gap-2">
        <h1>GitHub Runner Sources</h1>
        <a href="{{ route('server.runner-source.create') }}" class="button">+ Create Runner Source</a>
    </div>
    <div class="subtitle">Manage GitHub Actions runner pools powered by your own servers.</div>
    <div class="grid gap-4 lg:grid-cols-2 -mt-1">
        @forelse ($sources as $source)
            <a href="{{ route('server.runner-source.show', ['source_uuid' => $source->uuid]) }}"
                class="gap-2 border cursor-pointer box group">
                <div class="flex flex-col justify-center mx-6">
                    <div class="font-bold dark:text-white">
                        {{ $source->name }}
                    </div>
                    <div class="description">
                        Label: <code class="text-xs">{{ $source->runner_label }}</code>
                    </div>
                    <div class="flex gap-4 mt-2 text-xs">
                        <span class="text-neutral-400">
                            {{ $source->servers_count }} {{ Str::plural('server', $source->servers_count) }}
                        </span>
                        <span class="text-neutral-400">
                            {{ $source->runners_count }} active {{ Str::plural('runner', $source->runners_count) }}
                        </span>
                    </div>
                    @if ($source->organization)
                        <div class="mt-1 text-xs text-neutral-500">
                            Organization: {{ $source->organization }}
                        </div>
                    @endif
                </div>
                <div class="flex-1"></div>
            </a>
        @empty
            <div class="col-span-2">
                <div class="box">
                    <p class="mb-4">No runner sources configured yet.</p>
                    <p class="text-sm text-neutral-400">
                        Create a runner source to use your own servers as GitHub Actions runners.
                        This allows you to run GitHub Actions workflows on infrastructure you control.
                    </p>
                </div>
            </div>
        @endforelse
    </div>
</div>

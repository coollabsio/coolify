<x-layout>
    <x-slot:title>
        Sources | Coolify
    </x-slot>

    <div class="application-settings-form w-full">
    <header class="mb-5 flex items-center justify-between gap-4">
        <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">Sources</h1>
        <div class="shrink-0">
            @can('createAnyResource')
                <x-modal-input title="New GitHub App" :closeOutside="false">
                    <x-slot:content>
                        <button type="button"
                            class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                            <x-reicon name="plus" class="size-3.5" />
                            New source
                        </button>
                    </x-slot:content>
                    <livewire:source.github.create />
                </x-modal-input>
            @endcan
        </div>
    </header>

    <p class="mb-4 text-[11px] text-neutral-500 dark:text-fg-faint">
        {{ $sources->count() }} {{ Str::plural('Git source', $sources->count()) }} connected to your team
    </p>

    @if ($sources->isEmpty())
        <div
            class="flex min-h-80 flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-6 text-center dark:border-white/[0.1] dark:bg-white/[0.02]">
            <div
                class="mb-4 flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
                <x-reicon name="sources" class="size-5" />
            </div>
            <h2 class="text-[15px] font-semibold">No sources yet</h2>
            <p class="mt-1 max-w-sm text-[13px] text-neutral-500 dark:text-fg-dim">
                Connect a Git provider to deploy applications directly from your repositories.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($sources as $source)
                @if ($source->getMorphClass() === 'App\Models\GithubApp')
                    <a class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                        {{ wireNavigate() }}
                        href="{{ route('source.github.show', ['github_app_uuid' => data_get($source, 'uuid')]) }}">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon name="sources" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                    {{ $source->name }}
                                </h2>
                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                    {{ $source->organization ? "GitHub · {$source->organization}" : 'GitHub' }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-auto pt-4">
                            @if (is_null($source->app_id))
                                <x-status-badge label="Setup incomplete" type="warning" />
                            @else
                                <x-status-badge label="Connected" type="success" />
                            @endif
                        </div>
                    </a>
                @endif
            @endforeach
        </div>
    @endif
    </div>
</x-layout>

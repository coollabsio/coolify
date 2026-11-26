{{-- Top bar chip of a resource that coolify.json manages, with a popover that explains it. --}}
@props(['resource'])
@php
    $configLink = \App\Services\ProjectConfig\ManagedResources::link($resource);
@endphp
@if ($configLink)
    @php
        $commit = $configLink->lastAppliedRevision?->commit_sha;
        $environment = $configLink->environment;
        $configUrl = route('project.environment.configuration', ['project_uuid' => $environment->project->uuid, 'environment_uuid' => $environment->uuid]);
    @endphp
    <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
        <button type="button" aria-label="Managed by coolify.json" aria-haspopup="dialog" :aria-expanded="open" @click="open = !open"
            class="flex h-8 items-center justify-center gap-1.5 rounded-lg px-2 text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
            <x-reicon name="file-content" class="size-4" />
            <span class="hidden text-xs font-medium lg:inline">coolify.json</span>
        </button>
        <div x-show="open" x-cloak role="dialog"
            x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition duration-100 ease-out" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            class="surface-popover fixed top-14 left-1/2 z-[1100] w-[calc(100vw-2rem)] max-w-sm -translate-x-1/2 rounded-lg p-3 lg:absolute lg:top-full lg:right-0 lg:left-auto lg:mt-2 lg:translate-x-0">
            <div class="flex items-start gap-2.5">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-md bg-neutral-100 text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                    <x-reicon name="file-content" class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[13px] font-semibold leading-4 text-neutral-950 dark:text-fg">Managed by coolify.json</p>
                    <p class="mt-1 text-[12px] leading-4 text-neutral-600 dark:text-fg-dim">
                        @if ($configLink->isGit())
                            Settings come from <code>{{ $configLink->file_path }}</code> in <span class="break-all">{{ $configLink->repository }}</span>
                            on {{ $configLink->branch }}@if ($commit), commit <code>{{ substr($commit, 0, 7) }}</code>@endif.
                            Change the file and push to update them.
                        @else
                            Settings come from a coolify.json file applied through the API.
                        @endif
                        Deploys, logs, and the terminal work as usual.
                    </p>
                    <a href="{{ $configUrl }}" @if (wireNavigate()) wire:navigate @endif
                        class="mt-2 inline-flex items-center gap-0.5 text-[12px] font-semibold text-coollabs transition-colors hover:text-coollabs-100 dark:text-brand-light dark:hover:text-brand-light/80">
                        Open Config as code
                        <x-reicon name="arrow-right" class="size-2.5" />
                    </a>
                </div>
            </div>
        </div>
    </div>
@endif

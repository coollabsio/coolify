<x-layout>
    <x-slot:title>
        Sources | Coolify
    </x-slot>

    <div class="application-settings-form w-full">
        <header class="mb-5 flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Sources</h1>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ $sources->count() }} {{ Str::plural('Git source', $sources->count()) }} connected to your team
                </p>
            </div>
            @can('createAnyResource')
                <div x-data="{ dropdownOpen: false }" class="relative shrink-0"
                    @click.outside="dropdownOpen = false" @keydown.escape.window="dropdownOpen = false">
                    <button type="button" @click="dropdownOpen = !dropdownOpen"
                        class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!"
                        aria-haspopup="menu" :aria-expanded="dropdownOpen">
                        <x-reicon name="plus" class="size-3.5" />
                        New source
                        <x-reicon name="chevron-down" class="size-3 opacity-55" />
                    </button>

                    <div x-cloak x-show="dropdownOpen" x-transition.origin.top.right role="menu"
                        class="listbox-panel left-auto! right-0! z-[90]! w-52! min-w-52!">
                        <x-modal-input title="New GitHub App" :closeOutside="false">
                            <x-slot:content>
                                <button type="button" @click="dropdownOpen = false"
                                    class="listbox-option justify-start! gap-2.5!" role="menuitem">
                                    <x-git-icon class="size-3.5 shrink-0 opacity-70" git="App\Models\GithubApp" />
                                    GitHub App
                                </button>
                            </x-slot:content>
                            <livewire:source.github.create />
                        </x-modal-input>
                        <x-modal-input title="New GitLab App" :closeOutside="false">
                            <x-slot:content>
                                <button type="button" @click="dropdownOpen = false"
                                    class="listbox-option justify-start! gap-2.5!" role="menuitem">
                                    <x-git-icon class="size-3.5 shrink-0 opacity-70" git="App\Models\GitlabApp" />
                                    GitLab App
                                </button>
                            </x-slot:content>
                            <livewire:source.gitlab.create />
                        </x-modal-input>
                    </div>
                </div>
            @endcan
        </header>

        @if ($sources->isEmpty())
            <x-empty title="No sources yet"
                description="Connect a Git provider to deploy applications directly from your repositories."
                icon-name="sources" />
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
                                    <x-git-icon class="size-4" git="App\Models\GithubApp" />
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
                                @if ($source->isConnected())
                                    <x-status-badge label="Connected" type="success" />
                                @else
                                    <x-status-badge label="Setup incomplete" type="warning" />
                                @endif
                            </div>
                        </a>
                    @elseif ($source->getMorphClass() === 'App\Models\GitlabApp')
                        <a class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                            {{ wireNavigate() }}
                            href="{{ route('source.gitlab.show', ['gitlab_app_uuid' => data_get($source, 'uuid')]) }}">
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    <x-git-icon class="size-4" git="App\Models\GitlabApp" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                        {{ $source->name }}
                                    </h2>
                                    <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ $source->group_name ? "GitLab · {$source->group_name}" : 'GitLab' }}
                                    </p>
                                </div>
                            </div>

                            <div class="mt-auto pt-4">
                                @if ($source->isConnected())
                                    <x-status-badge label="Connected" type="success" />
                                @else
                                    <x-status-badge label="Setup required" type="warning" />
                                @endif
                            </div>
                        </a>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</x-layout>

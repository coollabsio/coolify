<x-layout>
    <x-slot:title>
        Sources | Coolify
    </x-slot>

    <div class="application-settings-form w-full">
        <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Sources</h1>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ $sources->count() }} {{ Str::plural('Git source', $sources->count()) }} connected to your team
                </p>
            </div>
            @can('createAnyResource')
                <div x-data="{ dropdownOpen: false }" class="relative w-fit shrink-0"
                    @click.outside="dropdownOpen = false" @keydown.escape.window="dropdownOpen = false">
                    <button type="button" @click="dropdownOpen = !dropdownOpen"
                        class="button button-highlighted"
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
                        <x-modal-input title="New Gitea / Forgejo App" :closeOutside="false">
                            <x-slot:content>
                                <button type="button" @click="dropdownOpen = false"
                                    class="listbox-option justify-start! gap-2.5!" role="menuitem">
                                    <x-git-icon class="size-3.5 shrink-0 opacity-70" git="App\Models\GiteaApp" />
                                    Gitea / Forgejo App
                                </button>
                            </x-slot:content>
                            <livewire:source.gitea.create />
                        </x-modal-input>
                        <x-modal-input title="New Origin App" :closeOutside="false">
                            <x-slot:content>
                                <button type="button" @click="dropdownOpen = false"
                                    class="listbox-option justify-start! gap-2.5!" role="menuitem">
                                    <x-git-icon class="size-3.5 shrink-0 opacity-70" git="App\Models\OriginApp" />
                                    Origin App
                                </button>
                            </x-slot:content>
                            <livewire:source.origin.create />
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
            @php
                $rows = $sources->map(function ($source) {
                    [$provider, $organization, $href] = match ($source->getMorphClass()) {
                        'App\Models\GithubApp' => ['GitHub', $source->accountName(), route('source.github.show', ['github_app_uuid' => $source->uuid])],
                        'App\Models\GitlabApp' => ['GitLab', $source->group_name, route('source.gitlab.show', ['gitlab_app_uuid' => $source->uuid])],
                        'App\Models\GiteaApp' => ['Gitea / Forgejo', $source->organization, route('source.gitea.show', ['gitea_app_uuid' => $source->uuid])],
                        'App\Models\OriginApp' => ['Origin', $source->namespace_slug, route('source.origin.show', ['origin_app_uuid' => $source->uuid])],
                    };

                    return [
                        'source' => $source,
                        'provider' => $provider,
                        'organization' => $organization,
                        'href' => $href,
                        'status' => $source->isConnected() ? 'Connected' : 'Setup incomplete',
                    ];
                })->values();
                $items = $rows->map(fn ($row) => [
                    'name' => $row['source']->name,
                    'provider' => $row['provider'],
                    'organization' => $row['organization'],
                    'status' => $row['status'],
                ]);
            @endphp
            <div x-data="{
                search: '', viewMode: localStorage.getItem('coolify-sources-view') || 'table', items: @js($items),
                get filteredItems() { const query = this.search.trim().toLowerCase(); return query ? this.items.filter(item => Object.values(item).some(value => String(value || '').toLowerCase().includes(query))) : this.items; },
                matches(values) { const query = this.search.trim().toLowerCase(); return !query || values.some(value => String(value || '').toLowerCase().includes(query)); },
                setViewMode(mode) { this.viewMode = mode; localStorage.setItem('coolify-sources-view', mode); }
            }">
            @include('livewire.shared.list-search-controls', ['placeholder' => 'Search sources'])
            <div x-cloak x-show="viewMode === 'grid'" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($rows as $row)
                    <a x-show="matches(@js([$row['source']->name, $row['provider'], $row['organization'], $row['status']]))" class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.05] dark:hover:border-white/[0.14]"
                        {{ wireNavigate() }} href="{{ $row['href'] }}">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-git-icon class="size-4" :git="$row['source']->getMorphClass()" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                    {{ $row['source']->name }}
                                </h2>
                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                    {{ $row['organization'] ? "{$row['provider']} · {$row['organization']}" : $row['provider'] }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-auto pt-4">
                            <x-status-badge :label="$row['status']" :type="$row['source']->isConnected() ? 'success' : 'warning'" />
                        </div>
                    </a>
                @endforeach
            </div>
            <div x-show="viewMode === 'table'" class="overflow-x-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
                <div class="grid min-w-[620px] grid-cols-[minmax(0,1fr)_minmax(10rem,.7fr)_9rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint"><div>Source</div><div>Provider</div><div>Status</div></div>
                @foreach ($rows as $row)
                    <a x-show="matches(@js([$row['source']->name, $row['provider'], $row['organization'], $row['status']]))" {{ wireNavigate() }} href="{{ $row['href'] }}" class="grid min-h-14 min-w-[620px] grid-cols-[minmax(0,1fr)_minmax(10rem,.7fr)_9rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                        <div class="truncate font-semibold text-black dark:text-fg">{{ $row['source']->name }}</div>
                        <div class="truncate text-neutral-500 dark:text-fg-dim">{{ $row['organization'] ? "{$row['provider']} · {$row['organization']}" : $row['provider'] }}</div>
                        <div><x-status-badge :label="$row['status']" :type="$row['source']->isConnected() ? 'success' : 'warning'" /></div>
                    </a>
                @endforeach
            </div>
            @include('livewire.shared.list-search-empty', ['label' => 'sources'])
            </div>
        @endif
    </div>
</x-layout>

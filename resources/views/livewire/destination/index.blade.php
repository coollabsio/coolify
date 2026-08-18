<div class="application-settings-form w-full">
    <x-slot:title>
        Destinations | Coolify
    </x-slot>

    <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Destinations</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ $destinations->count() }} {{ Str::plural('network endpoint', $destinations->count()) }}
            </p>
        </div>
        @if ($servers->count() > 0)
            @can('createAnyResource')
                <div class="w-fit shrink-0">
                    <x-modal-input title="New Destination">
                        <x-slot:content>
                            <button type="button"
                                class="button button-highlighted">
                                <x-reicon name="plus" class="size-3.5" />
                                New destination
                            </button>
                        </x-slot:content>
                        <livewire:destination.new.docker />
                    </x-modal-input>
                </div>
            @endcan
        @endif
    </header>

    @if ($destinations->isEmpty())
        <x-empty title="No destinations yet"
            description="Add a Docker network endpoint to choose where your resources are deployed."
            icon-name="destinations" />
    @else
        @php
            $items = $destinations->map(fn ($destination) => [
                'name' => $destination->name,
                'server' => $destination->server->name,
                'type' => $destination->getMorphClass() === 'App\\Models\\SwarmDocker' ? 'Docker Swarm' : 'Standalone Docker',
            ])->values();
        @endphp
        <div x-data="{
            search: '',
            viewMode: localStorage.getItem('coolify-destinations-view') || 'table',
            items: @js($items),
            get filteredItems() {
                const query = this.search.trim().toLowerCase();
                if (!query) return this.items;
                return this.items.filter(item => Object.values(item).some(value => String(value || '').toLowerCase().includes(query)));
            },
            matches(values) {
                const query = this.search.trim().toLowerCase();
                return !query || values.some(value => String(value || '').toLowerCase().includes(query));
            },
            setViewMode(mode) {
                this.viewMode = mode;
                localStorage.setItem('coolify-destinations-view', mode);
            }
        }">
            @include('livewire.shared.list-search-controls', ['placeholder' => 'Search destinations', 'singular' => 'destination', 'plural' => 'destinations'])

        <div x-cloak x-show="viewMode === 'grid'" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($destinations as $destination)
                <a x-show="matches(@js([$destination->name, $destination->server->name, $destination->getMorphClass() === 'App\\Models\\SwarmDocker' ? 'Docker Swarm' : 'Standalone Docker']))" class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                    {{ wireNavigate() }}
                    href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                            <x-reicon name="destinations" class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                {{ $destination->name }}
                            </h2>
                            <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                {{ $destination->server->name }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-auto flex items-center gap-2 pt-4">
                        @if ($destination->getMorphClass() === 'App\Models\SwarmDocker')
                            <x-status-badge label="Docker Swarm" type="warning" />
                        @else
                            <x-status-badge label="Standalone Docker" type="success" />
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
        <div x-show="viewMode === 'table'" class="overflow-x-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
            <div class="grid min-w-[620px] grid-cols-[minmax(0,1fr)_minmax(10rem,.7fr)_10rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                <div>Destination</div><div>Server</div><div>Type</div>
            </div>
            @foreach ($destinations as $destination)
                @php($isSwarm = $destination->getMorphClass() === 'App\\Models\\SwarmDocker')
                <a x-show="matches(@js([$destination->name, $destination->server->name, $isSwarm ? 'Docker Swarm' : 'Standalone Docker']))" {{ wireNavigate() }} href="{{ route('destination.show', ['destination_uuid' => $destination->uuid]) }}"
                    class="grid min-h-14 min-w-[620px] grid-cols-[minmax(0,1fr)_minmax(10rem,.7fr)_10rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                    <div class="truncate font-semibold text-black dark:text-fg">{{ $destination->name }}</div>
                    <div class="truncate text-neutral-500 dark:text-fg-dim">{{ $destination->server->name }}</div>
                    <div><x-status-badge :label="$isSwarm ? 'Docker Swarm' : 'Standalone Docker'" :type="$isSwarm ? 'warning' : 'success'" /></div>
                </a>
            @endforeach
        </div>
        @include('livewire.shared.list-search-empty', ['label' => 'destinations'])
        </div>
    @endif
</div>

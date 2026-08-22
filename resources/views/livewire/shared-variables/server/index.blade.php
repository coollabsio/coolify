<div>
    <x-slot:title>Server Variables | Coolify</x-slot>

    <x-shared-variables.layout>
        <div class="w-full" x-data="{
            search: '',
            viewMode: localStorage.getItem('shared-variables-servers-view') || 'grid',
            matches(values) {
                const query = this.search.trim().toLowerCase();
                return !query || values.some(value => String(value || '').toLowerCase().includes(query));
            }
        }">
            @if ($servers->isEmpty())
                <x-empty title="No servers yet" description="Add a server before creating server-wide variables." icon-name="servers" />
            @else
                <x-shared-variables.view-controls label="servers" storage-key="shared-variables-servers-view" />

                <div x-cloak x-show="viewMode === 'grid'" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($servers as $server)
                        <a x-show="matches(@js([$server->name, $server->description, $server->ip]))"
                            class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                            href="{{ route('shared-variables.server.show', ['server_uuid' => $server->uuid]) }}" {{ wireNavigate() }}>
                            <div class="flex items-start gap-3">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim"><x-reicon name="servers" class="size-4" /></div>
                                <div class="min-w-0 flex-1"><h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">{{ $server->name }}</h2><p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $server->description ?: $server->ip }}</p></div>
                            </div>
                            <div class="mt-auto pt-4"><x-status-badge :status="$server->isFunctional() ? 'Ready' : 'Validation required'" :type="$server->isFunctional() ? 'success' : 'warning'" /></div>
                        </a>
                    @endforeach
                </div>

                <div x-show="viewMode === 'list'" class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                    @foreach ($servers as $server)
                        <a x-show="matches(@js([$server->name, $server->description, $server->ip]))"
                            href="{{ route('shared-variables.server.show', ['server_uuid' => $server->uuid]) }}" {{ wireNavigate() }}
                            class="flex min-h-14 items-center gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                            <x-reicon name="servers" class="size-4 shrink-0 text-neutral-500 dark:text-fg-dim" />
                            <div class="min-w-0 flex-1"><div class="truncate text-[13px] font-medium">{{ $server->name }}</div><div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $server->description ?: $server->ip }}</div></div>
                            <x-status-badge :status="$server->isFunctional() ? 'Ready' : 'Validation required'" :type="$server->isFunctional() ? 'success' : 'warning'" />
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </x-shared-variables.layout>
</div>

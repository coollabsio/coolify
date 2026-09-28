<div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
    <div class="flex items-center gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 dark:border-white/[0.08] dark:bg-white/[0.05]">
        <x-reicon name="servers" class="size-4 shrink-0 text-neutral-500 dark:text-fg-dim" />
        <a href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}" {{ wireNavigate() }}
            class="min-w-0 truncate text-[13px] font-semibold text-black hover:underline dark:text-fg">
            {{ $server->name }}
        </a>
        <div class="ml-auto flex shrink-0 items-center gap-2">
            <button type="button" wire:click="loadRegistries" wire:loading.attr="disabled" class="button w-fit">
                <x-reicon name="refresh" class="size-3.5" />
                Refresh
            </button>
            @if ($server->isFunctional())
                <x-modal-input buttonTitle="Log in" title="Log in to a registry"
                    subtitle="Runs docker login on {{ $server->name }}.">
                    <livewire:server.docker-registries.login :server="$server"
                        :key="'registry-login-'.$server->uuid" />
                </x-modal-input>
            @endif
        </div>
    </div>

    @if ($error)
        <div class="px-4 py-3 text-[12px] text-red-700 dark:text-red-300">{{ $error }}</div>
    @endif

    @if (empty($registries))
        @unless ($error)
            <div class="px-4 py-3 text-[12px] text-neutral-500 dark:text-fg-faint">
                Not logged in to any registry, and no application on this server uses a registry image.
            </div>
        @endunless
    @else
        <div class="overflow-x-auto">
            <div
                class="grid min-w-[680px] grid-cols-[minmax(0,1fr)_9rem_minmax(0,1fr)_10rem] border-b border-neutral-200 px-4 py-2 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                <div>Registry</div>
                <div>Status</div>
                <div>Used by</div>
                <div></div>
            </div>
            @foreach ($registries as $row)
                @php
                    $usedByCount = count($row['used_by']);
                    $usedBySummary = collect($row['used_by'])->countBy('type')
                        ->map(fn (int $count, string $type) => $count.' '.Str::plural(strtolower($type), $count))
                        ->implode(' · ');
                @endphp
                <div wire:key="{{ $server->uuid }}-{{ $row['registry'] }}" x-data="{ showUsers: false }"
                    class="min-w-[680px] border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                    <div class="grid min-h-11 grid-cols-[minmax(0,1fr)_9rem_minmax(0,1fr)_10rem] items-center px-4 py-2 text-[12px]">
                        <div class="truncate font-mono">{{ $row['registry'] }}</div>
                        <div>
                            @if ($row['logged_in'])
                                <x-status-badge type="success"
                                    :label="$row['source'] === 'credHelpers' ? 'Credential helper' : 'Logged in'" />
                            @elseif ($error)
                                <x-status-badge label="Unknown" />
                            @else
                                <x-status-badge :type="$row['registry'] === 'docker.io' ? 'neutral' : 'warning'" label="Not logged in"
                                    :title="$row['registry'] === 'docker.io' ? 'Public Docker Hub images do not need a login.' : null" />
                            @endif
                        </div>
                        <div class="min-w-0 text-neutral-600 dark:text-fg-dim">
                            @if ($usedByCount === 0)
                                <span class="text-neutral-400 dark:text-fg-faint">-</span>
                            @elseif ($usedByCount === 1)
                                @include('livewire.server.docker-registries.used-by-item', ['user' => $row['used_by'][0]])
                            @else
                                <button type="button" x-on:click="showUsers = !showUsers" :aria-expanded="showUsers"
                                    class="inline-flex max-w-full items-center gap-1 hover:text-black dark:hover:text-fg">
                                    <span class="truncate">{{ $usedBySummary }}</span>
                                    <x-reicon name="chevron-down" class="size-3 shrink-0 transition-transform"
                                        x-bind:class="showUsers && 'rotate-180'" />
                                </button>
                            @endif
                        </div>
                        <div class="flex justify-end gap-2">
                            @if ($row['logged_in'] && $row['source'] === 'auths')
                                <x-modal-input buttonTitle="Edit" title="Edit login for {{ $row['registry'] }}"
                                    subtitle="Runs docker login on {{ $server->name }} again. The new values replace the old login.">
                                    <livewire:server.docker-registries.login :server="$server" :editRegistry="$row['registry']"
                                        :currentUsername="$row['username']"
                                        :key="'registry-edit-'.$server->uuid.'-'.$row['registry']" />
                                </x-modal-input>
                                <x-modal-confirmation title="Log out from {{ $row['registry'] }}?" buttonTitle="Log out"
                                    submitAction="logout({{ $row['registry'] }})" :actions="[
                                        'The server can no longer pull private images from this registry.',
                                        'Deployments that need this registry fail until you log in again.',
                                    ]" :confirmWithText="false" :confirmWithPassword="false" />
                            @endif
                        </div>
                    </div>
                    @if ($usedByCount > 1)
                        <div x-show="showUsers" x-cloak
                            class="grid gap-x-6 gap-y-3 bg-neutral-50 px-4 py-3 text-[12px] sm:grid-cols-3 dark:bg-white/[0.02]">
                            @foreach (collect($row['used_by'])->groupBy('type') as $type => $users)
                                <div class="min-w-0">
                                    <p class="mb-1 text-[11px] font-medium text-neutral-500 dark:text-fg-faint">
                                        {{ Str::plural($type) }} ({{ $users->count() }})
                                    </p>
                                    <ul class="flex flex-col gap-0.5 text-neutral-600 dark:text-fg-dim">
                                        @foreach ($users as $user)
                                            <li class="truncate">
                                                @include('livewire.server.docker-registries.used-by-item', ['user' => $user, 'withType' => false])
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>

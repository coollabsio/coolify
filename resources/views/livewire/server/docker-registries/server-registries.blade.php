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
                <div wire:key="{{ $server->uuid }}-{{ $row['registry'] }}"
                    class="grid min-h-11 min-w-[680px] grid-cols-[minmax(0,1fr)_9rem_minmax(0,1fr)_10rem] items-center border-b border-neutral-200 px-4 py-2 text-[12px] last:border-b-0 dark:border-white/[0.07]">
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
                    <div class="flex min-w-0 flex-wrap gap-x-2 text-neutral-600 dark:text-fg-dim">
                        @forelse ($row['used_by'] as $application)
                            @if ($application['link'])
                                <a href="{{ $application['link'] }}" {{ wireNavigate() }} class="truncate hover:underline">{{ $application['name'] }}</a>
                            @else
                                <span class="truncate">{{ $application['name'] }}</span>
                            @endif
                        @empty
                            <span class="text-neutral-400 dark:text-fg-faint">-</span>
                        @endforelse
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
            @endforeach
        </div>
    @endif
</div>

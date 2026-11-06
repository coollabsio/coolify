<div @if ($isRunning) wire:poll.3s @endif>
    {{-- The result of the last rotation stays reachable after every server moved. --}}
    @if ($hasServers || $isRunning || $open || $state)
        <x-modal-input title="Rotate key" :buttonTitle="$hasServers || $isRunning ? 'Rotate key' : 'Rotation result'" :wireIgnore="false" wireOpen="open" :closeEvent="false"
            subtitle="Move the servers to a new key. Servers that fail stay on this key.">
            <div class="flex flex-col gap-3 text-sm">
                <ul class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.06]">
                    @foreach ($rows as $uuid => $row)
                        <li wire:key="rotate-row-{{ $uuid }}" class="flex flex-col gap-0.5 py-2">
                            <span class="flex items-center gap-2">
                                <span>{{ data_get($row, 'name') }}</span>
                                <span class="ml-auto">
                                    @switch(data_get($row, 'status'))
                                        @case('moved')
                                        @case('moved_old_pending')
                                            <x-status-badge size="sm" label="Moved" type="success" />
                                        @break
                                        @case('moved_old_not_removed')
                                            <x-status-badge size="sm" label="Moved" type="warning" />
                                        @break
                                        @case('failed')
                                            <x-status-badge size="sm" label="Failed" type="error" />
                                        @break
                                        @case('old_key_not_found')
                                            <x-status-badge size="sm" label="Old key not found" type="error" />
                                        @break
                                        @case('unreachable')
                                            <x-status-badge size="sm" label="Unreachable" type="error" />
                                        @break
                                        @case('skipped')
                                            <x-status-badge size="sm" label="Transferred" type="warning" />
                                        @break
                                        @default
                                            @if (data_get($row, 'transferred'))
                                                <x-status-badge size="sm" label="Transferred" type="warning" />
                                            @elseif ($isRunning)
                                                <x-status-badge size="sm" label="Waiting" pulse />
                                            @elseif (! data_get($row, 'reachable'))
                                                <x-status-badge size="sm" label="Not reachable" type="warning" />
                                            @endif
                                    @endswitch
                                </span>
                            </span>
                            @if (! in_array(data_get($row, 'status'), [null, 'moved'], true))
                                <span class="text-xs text-neutral-500 dark:text-fg-dim">{{ data_get($row, 'message') }}</span>
                            @elseif (data_get($row, 'status') === null && data_get($row, 'transferred'))
                                <span class="text-xs text-neutral-500 dark:text-fg-dim">Another Coolify instance manages this server now.</span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if (! $state)
                    <p class="text-xs text-neutral-500 dark:text-fg-dim">Rotation progress and logs will appear here.</p>
                @endif
                @if ($hasServers && ! $isRunning && ! $newKey)
                    <x-forms.listbox id="keyType" label="New key type" :options="[
                        ['value' => 'ed25519', 'label' => 'Ed25519', 'description' => 'Recommended.'],
                        ['value' => 'rsa', 'label' => 'RSA 4096', 'description' => 'For hosts that do not accept Ed25519, for example FIPS hosts.'],
                    ]" />
                @endif
                @if (data_get($state, 'status') === 'failed')
                    <p class="text-xs text-red-500">{{ data_get($state, 'error') }}</p>
                @endif
                @if ($newKey)
                    <p class="text-xs text-neutral-500 dark:text-fg-dim">
                        New key:
                        <span class="font-medium">{{ $newKey->name }}</span>
                    </p>
                @endif
                @if ($usedByOthers)
                    <p class="text-xs text-neutral-500 dark:text-fg-dim">Applications and Git sources stay on this key.</p>
                @elseif (! $isRunning && ! $hasServers)
                    <p class="text-xs text-neutral-500 dark:text-fg-dim">Nothing uses this key now. You can delete it.</p>
                @endif
                <div class="flex items-center justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                    @if (! $isRunning && ! $hasServers && ! $usedByOthers)
                        @can('delete', $privateKey)
                            <x-forms.button isError
                                x-on:click="modalOpen = false; $dispatch('confirm-old-key-deletion', { id: {{ $privateKey->id }} })">
                                Delete old key
                            </x-forms.button>
                        @endcan
                    @endif
                    @if ($hasServers || $isRunning)
                        <x-forms.button isHighlighted wire:click="rotate" :disabled="$isRunning">
                            {{ $isRunning ? 'Rotating...' : ($state ? 'Rotate again' : 'Rotate key') }}
                        </x-forms.button>
                    @endif
                </div>
            </div>
        </x-modal-input>
    @endif
</div>

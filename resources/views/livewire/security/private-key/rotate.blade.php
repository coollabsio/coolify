<div @if ($isRunning) wire:poll.3s @endif>
    @if ($rows->isNotEmpty())
        <x-modal-input title="Rotate key" buttonTitle="Rotate key" :wireIgnore="false"
            subtitle="Move the servers to a new key. Servers that fail stay on this key.">
            <div class="flex flex-col gap-3 text-sm">
                <ul class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.06]">
                    @foreach ($rows as $uuid => $row)
                        <li wire:key="rotate-row-{{ $uuid }}" class="flex flex-col gap-0.5 py-2">
                            <span class="flex items-center gap-2">
                                <span>{{ data_get($row, 'name') }}</span>
                                <span class="text-xs text-neutral-500 dark:text-fg-dim">{{ data_get($row, 'ip') }}</span>
                                <span class="ml-auto">
                                    @switch(data_get($row, 'status'))
                                        @case('moved')
                                            <x-status-badge size="sm" label="Moved" type="success" />
                                        @break
                                        @case('moved_old_not_removed')
                                            <x-status-badge size="sm" label="Moved" type="warning" />
                                        @break
                                        @case('failed')
                                            <x-status-badge size="sm" label="Failed" type="error" />
                                        @break
                                        @case('unreachable')
                                            <x-status-badge size="sm" label="Unreachable" type="error" />
                                        @break
                                        @default
                                            @if ($isRunning)
                                                <x-status-badge size="sm" label="Waiting" pulse />
                                            @elseif (! data_get($row, 'reachable'))
                                                <x-status-badge size="sm" label="Not reachable" type="warning" />
                                            @endif
                                    @endswitch
                                </span>
                            </span>
                            @if (! in_array(data_get($row, 'status'), [null, 'moved'], true))
                                <span class="text-xs text-neutral-500 dark:text-fg-dim">{{ data_get($row, 'message') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if (data_get($state, 'status') === 'failed')
                    <p class="text-xs text-red-500">{{ data_get($state, 'error') }}</p>
                @endif
                @if ($newKey)
                    <p class="text-xs text-neutral-500 dark:text-fg-dim">
                        New key:
                        <a class="underline" {{ wireNavigate() }}
                            href="{{ route('security.private-key.show', ['private_key_uuid' => $newKey->uuid]) }}">{{ $newKey->name }}</a>
                    </p>
                @endif
                @if ($usedByOthers)
                    <p class="text-xs text-neutral-500 dark:text-fg-dim">Applications and Git sources stay on this key.</p>
                @elseif (! $isRunning && ! $hasServers)
                    <p class="text-xs text-neutral-500 dark:text-fg-dim">Nothing uses this key now. You can delete it.</p>
                @endif
            </div>
            <x-slot:footer>
                <x-forms.button isHighlighted wire:click="rotate" :disabled="$isRunning || ! $hasServers">
                    {{ $isRunning ? 'Rotating...' : ($state ? 'Rotate again' : 'Rotate key') }}
                </x-forms.button>
            </x-slot:footer>
        </x-modal-input>
    @endif
</div>

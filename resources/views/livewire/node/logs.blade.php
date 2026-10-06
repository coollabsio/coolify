<div>
    <x-slot:title>
        Logs | {{ data_get_str($node, 'name')->limit(24) }} | Coolify
    </x-slot>

    <x-node.navbar :node="$node" />

    <div
        class="node-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-node.sidebar :node="$node" activeMenu="logs" />

        <div class="flex w-full min-w-0 flex-col gap-6" @if ($autoRefresh) wire:poll.5s="pollLogs" @endif>
            <x-application.settings-section title="Logs" flush
                helper="Recent events from the services that run on this server. Logs are read on demand through Flux. When the server cannot answer through Flux, Coolify reads the system journal over SSH.">
                <x-slot:actions>
                    <x-forms.button type="button" wire:click="refreshLogs"
                        title="Refresh logs">
                        <x-reicon name="refresh" class="size-3.5" wire:loading.class="animate-spin"
                            wire:target="refreshLogs" />
                        Refresh
                    </x-forms.button>
                </x-slot:actions>

                <div
                    class="grid gap-3 border-b border-neutral-200 p-4 sm:grid-cols-3 dark:border-white/[0.08]">
                    <x-forms.listbox id="source" label="Source" live :options="collect($sources)
                        ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                        ->values()
                        ->all()" />
                    <x-forms.listbox id="level" label="Level" live :options="collect($levelFilters)
                        ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                        ->values()
                        ->all()" />
                    <x-forms.listbox id="limit" label="Events" live :options="collect($limits)
                        ->map(fn ($limit) => ['value' => $limit, 'label' => $limit.' events'])
                        ->all()" />
                </div>

                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-2 text-[12px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($transport === 'flux')
                            <x-status-badge status="Via Flux" type="success" />
                        @elseif ($transport === 'ssh')
                            <x-status-badge status="Via SSH fallback" type="warning" />
                        @endif
                        @if ($transport)
                            @php
                                $visibleCount = count($visibleEvents);
                                $summary = "Showing {$visibleCount} ".str('event')->plural($visibleCount);
                                if ($visibleCount !== count($events)) {
                                    $summary .= ' of '.count($events);
                                }
                                if ($truncated) {
                                    $summary .= ' (truncated)';
                                }
                            @endphp
                            <span>{{ $summary }}</span>
                        @endif
                    </div>
                    <x-forms.checkbox id="autoRefresh" instantSave="toggleAutoRefresh"
                        label="Auto-refresh every 5 seconds" />
                </div>

                @if ($loadError)
                    <div class="p-4">
                        <x-callout type="warning" title="Logs are unavailable">{{ $loadError }}</x-callout>
                    </div>
                @elseif (empty($visibleEvents))
                    <div class="p-4">
                        <x-empty size="sm" title="No log events"
                            description="No events match the selected source and level." icon-name="file-content" />
                    </div>
                @else
                    <div x-data="{ formatTime: (ms) => new Date(ms).toLocaleString() }"
                        x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                        class="max-h-[36rem] overflow-auto bg-neutral-50 px-4 py-3 font-mono text-[11px] leading-5 dark:bg-black/30">
                        @foreach ($visibleEvents as $event)
                            <div data-log-level="{{ $event['level'] }}" class="flex min-w-0 gap-3 whitespace-pre-wrap wrap-anywhere">
                                <time class="shrink-0 text-neutral-500 dark:text-fg-faint"
                                    datetime="{{ \Carbon\Carbon::createFromTimestampMs($event['timestamp_unix_ms'])->toIso8601String() }}"
                                    x-text="formatTime({{ $event['timestamp_unix_ms'] }})">{{ \Carbon\Carbon::createFromTimestampMs($event['timestamp_unix_ms'])->toDateTimeString() }}</time>
                                <span @class([
                                    'w-12 shrink-0 font-semibold uppercase',
                                    'text-red-500' => $event['level'] === 'error',
                                    'text-orange-500 dark:text-warning' => $event['level'] === 'warn',
                                    'text-neutral-700 dark:text-fg-dim' => $event['level'] === 'info',
                                    'text-neutral-400 dark:text-fg-faint' => in_array($event['level'], ['debug', 'trace'], true),
                                ])>{{ $event['level'] }}</span>
                                @if ($event['component'] !== '')
                                    <span class="shrink-0 text-neutral-500 dark:text-fg-faint">{{ $event['component'] }}</span>
                                @endif
                                <span class="min-w-0 text-black dark:text-fg">{{ $event['message'] }}@foreach ($event['fields'] as $fieldName => $fieldValue) <span class="text-neutral-500 dark:text-fg-faint">{{ $fieldName }}={{ $fieldValue }}</span>@endforeach</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </div>
</div>

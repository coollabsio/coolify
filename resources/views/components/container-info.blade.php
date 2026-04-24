@props(['containerInfo' => null])

@if ($containerInfo)
    <div class="rounded border border-neutral-200 bg-white p-4 dark:border-coolgray-300 dark:bg-coolgray-100">
        <div class="flex items-center gap-2">
            <h3>Container Info</h3>
            <span class="text-xs text-neutral-500 dark:text-neutral-400">Read-only details from Docker inspect.</span>
        </div>
        <div class="grid gap-4 pt-4 xl:grid-cols-2">
            <div class="space-y-1">
                <div class="text-xs uppercase text-neutral-500 dark:text-neutral-400">Container ID</div>
                <div class="flex items-start justify-between gap-2 rounded border border-neutral-200 px-3 py-2 dark:border-coolgray-300">
                    <code class="break-all text-xs">{{ data_get($containerInfo, 'id') }}</code>
                    @if (filled(data_get($containerInfo, 'id')))
                        <button type="button" class="text-xs font-semibold text-coollabs hover:underline dark:text-warning"
                            x-on:click="copyToClipboard(@js(data_get($containerInfo, 'id')))" aria-label="Copy container ID"
                            title="Copy container ID">
                            Copy
                        </button>
                    @endif
                </div>
            </div>

            <div class="space-y-1">
                <div class="text-xs uppercase text-neutral-500 dark:text-neutral-400">Container Name</div>
                <div class="flex items-start justify-between gap-2 rounded border border-neutral-200 px-3 py-2 dark:border-coolgray-300">
                    <code class="break-all text-xs">{{ data_get($containerInfo, 'name') }}</code>
                    @if (filled(data_get($containerInfo, 'name')))
                        <button type="button" class="text-xs font-semibold text-coollabs hover:underline dark:text-warning"
                            x-on:click="copyToClipboard(@js(data_get($containerInfo, 'name')))" aria-label="Copy container name"
                            title="Copy container name">
                            Copy
                        </button>
                    @endif
                </div>
            </div>

            <div class="space-y-1 xl:col-span-2">
                <div class="text-xs uppercase text-neutral-500 dark:text-neutral-400">Image</div>
                <div class="rounded border border-neutral-200 px-3 py-2 dark:border-coolgray-300">
                    <code class="break-all text-xs">{{ data_get($containerInfo, 'image') }}</code>
                </div>
            </div>

            <div class="space-y-1">
                <div class="text-xs uppercase text-neutral-500 dark:text-neutral-400">Created At</div>
                <div class="rounded border border-neutral-200 px-3 py-2 text-xs dark:border-coolgray-300">
                    {{ data_get($containerInfo, 'created_at') ?? '—' }}
                </div>
            </div>

            <div class="space-y-1">
                <div class="text-xs uppercase text-neutral-500 dark:text-neutral-400">Started At</div>
                <div class="rounded border border-neutral-200 px-3 py-2 text-xs dark:border-coolgray-300">
                    {{ data_get($containerInfo, 'started_at') ?? '—' }}
                </div>
            </div>

            <div class="space-y-2">
                <div class="text-xs uppercase text-neutral-500 dark:text-neutral-400">IPv4</div>
                @forelse (data_get($containerInfo, 'ipv4_addresses', []) as $ipv4Address)
                    <div class="flex items-start justify-between gap-2 rounded border border-neutral-200 px-3 py-2 dark:border-coolgray-300">
                        <code class="break-all text-xs">{{ $ipv4Address }}</code>
                        <button type="button" class="text-xs font-semibold text-coollabs hover:underline dark:text-warning"
                            x-on:click="copyToClipboard(@js($ipv4Address))" aria-label="Copy IPv4 address"
                            title="Copy IPv4 address">
                            Copy
                        </button>
                    </div>
                @empty
                    <div class="rounded border border-dashed border-neutral-200 px-3 py-2 text-xs text-neutral-500 dark:border-coolgray-300 dark:text-neutral-400">
                        —
                    </div>
                @endforelse
            </div>

            <div class="space-y-2">
                <div class="text-xs uppercase text-neutral-500 dark:text-neutral-400">IPv6</div>
                @forelse (data_get($containerInfo, 'ipv6_addresses', []) as $ipv6Address)
                    <div class="flex items-start justify-between gap-2 rounded border border-neutral-200 px-3 py-2 dark:border-coolgray-300">
                        <code class="break-all text-xs">{{ $ipv6Address }}</code>
                        <button type="button" class="text-xs font-semibold text-coollabs hover:underline dark:text-warning"
                            x-on:click="copyToClipboard(@js($ipv6Address))" aria-label="Copy IPv6 address"
                            title="Copy IPv6 address">
                            Copy
                        </button>
                    </div>
                @empty
                    <div class="rounded border border-dashed border-neutral-200 px-3 py-2 text-xs text-neutral-500 dark:border-coolgray-300 dark:text-neutral-400">
                        —
                    </div>
                @endforelse
            </div>
        </div>
    </div>
@endif

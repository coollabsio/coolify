@php
    $containerInfo = $containerInfo ?? [];
    $networks = data_get($containerInfo, 'networks', []);
@endphp

<div class="flex flex-col gap-6">
    <div>
        <h2>Container Info</h2>
        <div class="text-sm text-neutral-500">
            Live container metadata pulled from the current server.
        </div>
    </div>

    @if ($containerInfoError)
        <x-callout type="warning" title="Container metadata unavailable">
            {{ $containerInfoError }}
        </x-callout>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
        <div class="flex flex-col gap-4">
            <div class="box-without-bg-without-border dark:bg-coolgray-100 bg-white">
                <h3 class="pb-4">Runtime</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <div class="pb-2 text-xs uppercase text-neutral-500">Container ID</div>
                        <x-forms.copy-button :text="data_get($containerInfo, 'container_id', 'Unavailable')" />
                    </div>
                    <div class="sm:col-span-2">
                        <div class="pb-2 text-xs uppercase text-neutral-500">Container Name</div>
                        <x-forms.copy-button :text="data_get($containerInfo, 'container_name', 'Unavailable')" />
                    </div>
                    <div>
                        <div class="pb-1 text-xs uppercase text-neutral-500">Status</div>
                        <div>{{ str(data_get($containerInfo, 'status', 'unknown'))->headline() }}</div>
                    </div>
                    <div>
                        <div class="pb-1 text-xs uppercase text-neutral-500">Restart Count</div>
                        <div>{{ data_get($containerInfo, 'restart_count', 0) }}</div>
                    </div>
                    <div>
                        <div class="pb-1 text-xs uppercase text-neutral-500">Created</div>
                        @if (data_get($containerInfo, 'created_at'))
                            <div>{{ data_get($containerInfo, 'created_at.display') }}</div>
                            <div class="text-xs text-neutral-500">{{ data_get($containerInfo, 'created_at.human') }}</div>
                        @else
                            <div class="text-neutral-500">Unavailable</div>
                        @endif
                    </div>
                    <div>
                        <div class="pb-1 text-xs uppercase text-neutral-500">Started</div>
                        @if (data_get($containerInfo, 'started_at'))
                            <div>{{ data_get($containerInfo, 'started_at.display') }}</div>
                            <div class="text-xs text-neutral-500">{{ data_get($containerInfo, 'started_at.human') }}</div>
                        @else
                            <div class="text-neutral-500">Unavailable</div>
                        @endif
                    </div>
                    <div class="sm:col-span-2">
                        <div class="pb-2 text-xs uppercase text-neutral-500">Image Reference</div>
                        <x-forms.copy-button :text="data_get($containerInfo, 'image_reference', 'Unavailable')" />
                    </div>
                    <div class="sm:col-span-2">
                        <div class="pb-2 text-xs uppercase text-neutral-500">Image Hash</div>
                        <x-forms.copy-button :text="data_get($containerInfo, 'image_hash', 'Unavailable')" />
                    </div>
                    @if (data_get($containerInfo, 'image_digest'))
                        <div class="sm:col-span-2">
                            <div class="pb-2 text-xs uppercase text-neutral-500">Image Digest</div>
                            <x-forms.copy-button :text="data_get($containerInfo, 'image_digest')" />
                        </div>
                    @endif
                    @if (data_get($containerInfo, 'hostname'))
                        <div class="sm:col-span-2">
                            <div class="pb-2 text-xs uppercase text-neutral-500">Hostname</div>
                            <x-forms.copy-button :text="data_get($containerInfo, 'hostname')" />
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-4">
            <div class="box-without-bg-without-border dark:bg-coolgray-100 bg-white">
                <div class="flex items-center justify-between pb-4">
                    <h3>Networks</h3>
                    <div class="text-xs text-neutral-500">
                        {{ count($networks) }} attached
                    </div>
                </div>
                @if (empty($networks))
                    <div class="text-sm text-neutral-500">
                        No network metadata is available for this container right now.
                    </div>
                @else
                    <div class="flex flex-col gap-4">
                        @foreach ($networks as $network)
                            <div class="rounded-sm border border-neutral-200 p-4 dark:border-coolgray-300">
                                <div class="pb-3 font-medium">{{ data_get($network, 'name') }}</div>
                                <div class="grid gap-4">
                                    <div>
                                        <div class="pb-2 text-xs uppercase text-neutral-500">IPv4</div>
                                        <x-forms.copy-button :text="data_get($network, 'ipv4', 'Unavailable')" />
                                    </div>
                                    <div>
                                        <div class="pb-2 text-xs uppercase text-neutral-500">IPv6</div>
                                        <x-forms.copy-button :text="data_get($network, 'ipv6', 'Unavailable')" />
                                    </div>
                                    <div>
                                        <div class="pb-2 text-xs uppercase text-neutral-500">MAC Address</div>
                                        <x-forms.copy-button :text="data_get($network, 'mac', 'Unavailable')" />
                                    </div>
                                    @if (data_get($network, 'gateway'))
                                        <div>
                                            <div class="pb-2 text-xs uppercase text-neutral-500">Gateway</div>
                                            <x-forms.copy-button :text="data_get($network, 'gateway')" />
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

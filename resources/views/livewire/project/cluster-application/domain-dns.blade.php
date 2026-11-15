@php
    $rows = collect($hostnames)->map(function (array $row) use ($addresses): array {
        $automatic = count($row['zones']) === 1 && $row['zones'][0]['automatic'];
        [$status, $type] = match (true) {
            str_ends_with($row['hostname'], '.sslip.io') => ['Generated domain', 'neutral'],
            $row['zones'] === [] => ['Manual', 'neutral'],
            filled($row['problem']) => ['Failed', 'error'],
            $addresses !== [] && $row['missing'] === [] => ['Managed', 'success'],
            count($row['zones']) > 1 => ['Choose a provider', 'warning'],
            $automatic && $addresses !== [] => ['Pending', 'warning'],
            default => ['Not created', 'neutral'],
        };

        return [...$row, 'status' => $status, 'type' => $type, 'automatic' => $automatic];
    });
    $isPending = $rows->contains('status', 'Pending');
@endphp

<div>
    @if ($rows->isNotEmpty())
        <x-application.settings-section id="cluster-application-dns-records" title="DNS records" :flush="true"
            helper="Coolify creates an A or AAAA record for each public address of the ingress servers, when one connected DNS provider of the team manages the domain. It changes and deletes only records that it created.">
            @if ($isPending)
                <div class="hidden" wire:poll.5s aria-hidden="true"></div>
            @endif
            @if ($addresses === [])
                <div class="px-4 pt-4">
                    <x-callout type="warning" title="No public ingress address">
                        No ingress server of the cluster has a public address, so Coolify cannot create DNS records.
                    </x-callout>
                </div>
            @endif
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] table-fixed text-left text-sm">
                    <thead class="text-[12px] uppercase tracking-wide text-neutral-500 dark:text-fg-dim">
                        <tr class="border-b border-neutral-200 dark:border-white/10">
                            <th class="w-[26%] px-4 py-2 font-medium">Domain</th>
                            <th class="w-[20%] px-4 py-2 font-medium">DNS provider</th>
                            <th class="w-[20%] px-4 py-2 font-medium">Records</th>
                            <th class="w-[20%] px-4 py-2 font-medium">Status</th>
                            <th class="w-[14%] px-4 py-2 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-white/10">
                        @foreach ($rows as $row)
                            <tr wire:key="cluster-application-dns-record-{{ $row['hostname'] }}" class="align-top text-[13px] text-black dark:text-fg">
                                <td class="px-4 py-3" style="overflow-wrap: anywhere; white-space: normal">{{ $row['hostname'] }}</td>
                                <td class="px-4 py-3" style="overflow-wrap: anywhere; white-space: normal">
                                    @forelse ($row['zones'] as $zone)
                                        <div>{{ $zone['credential'] }} · {{ $zone['zone'] }}</div>
                                        @unless ($zone['automatic'])
                                            <div class="text-[12px] text-neutral-500 dark:text-fg-dim">Automatic DNS is off</div>
                                        @endunless
                                    @empty
                                        <span class="text-neutral-500 dark:text-fg-dim"
                                            title="No connected DNS provider of the team manages this domain. Create the records at your DNS provider.">None</span>
                                    @endforelse
                                </td>
                                <td class="px-4 py-3" style="overflow-wrap: anywhere; white-space: normal">
                                    @forelse ($row['records'] as $record)
                                        <div class="flex flex-wrap items-center gap-x-2">
                                            <span class="font-mono text-[12px]">{{ $record['type'] }} {{ $record['content'] }}</span>
                                            <span class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $record['owned'] ? 'Created by Coolify' : 'Existing record' }}</span>
                                        </div>
                                    @empty
                                        <span class="text-neutral-500 dark:text-fg-dim">None</span>
                                    @endforelse
                                    @if ($row['records'] !== [] && $row['missing'] !== [])
                                        <div class="text-[12px] text-neutral-500 dark:text-fg-dim">Missing: {{ implode(', ', $row['missing']) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3" style="overflow-wrap: anywhere; white-space: normal">
                                    <x-status-badge :status="$row['status']" :type="$row['type']" :pulse="$row['status'] === 'Pending'" />
                                    @if (filled($row['problem']))
                                        <div class="pt-1 text-[12px] break-words whitespace-normal text-red-600 dark:text-red-400">{{ $row['problem'] }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @can('update', $workload)
                                        @if ($addresses !== [] && $row['missing'] !== [] && $row['status'] !== 'Pending')
                                            <div class="flex flex-col items-end gap-1">
                                                @foreach ($row['zones'] as $zone)
                                                    <x-forms.button type="button"
                                                        wire:click="createRecords({{ \Illuminate\Support\Js::from($row['hostname']) }}, {{ $zone['id'] }})"
                                                        wire:target="createRecords">
                                                        {{ count($row['zones']) > 1 ? 'Create in '.$zone['credential'] : 'Create records' }}
                                                    </x-forms.button>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-application.settings-section>
    @endif
</div>

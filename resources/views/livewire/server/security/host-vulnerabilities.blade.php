<div>
        @php
            $hostSummary = $hostScan?->summary;
            $updatesByPackage = collect(data_get($result, 'updates', []))->keyBy('package');
            $hostScanError = $hostScan?->error_code?->message(['server' => $server->name]);
        @endphp
        <x-application.settings-section id="server-host-vulnerabilities-section" title="Vulnerabilities"
            helper="Known vulnerabilities in the operating system packages of this server. Coolify scans them with the image scanner every day and after a package update. Problems that the distribution does not fix are not shown."
            flush>
            <x-slot:badges>
                @if ($hostScanError && ! $hostScanRunning)
                    <x-status-badge status="Scan failed" type="error" />
                @endif
                @if ($hostSummary)
                    @foreach (['critical', 'high'] as $severity)
                        @if (($hostSummary[$severity] ?? 0) > 0)
                            <x-image-scan.severity-badge :severity="$severity" :count="$hostSummary[$severity]" />
                        @endif
                    @endforeach
                    @if (($hostSummary['exploited'] ?? 0) > 0)
                        <x-status-badge :status="$hostSummary['exploited'] . ' exploited'" type="error" />
                    @endif
                    @if (array_sum(array_intersect_key($hostSummary, array_flip(['critical', 'high', 'medium', 'low', 'unknown']))) === 0)
                        <x-status-badge status="No relevant problems" type="success" />
                    @endif
                @elseif (! $hostScanAvailable)
                    <x-status-badge status="Off" type="neutral" />
                @elseif (! $hostScanRunning)
                    <x-status-badge status="Not scanned yet" type="neutral" />
                @endif
            </x-slot:badges>
            @if ($hostFindings !== [] || $hostScanAvailable)
                <x-slot:actions>
                    @if ($hostFindings !== [])
                        <div class="w-56">
                            <x-table.search placeholder="Search by ID or package" loading-target="search"
                                wire:model.live.debounce.300ms="search" />
                        </div>
                    @endif
                    @if ($hostScanAvailable)
                        @can('update', $server)
                            <x-forms.button type="button" wire:click="scanHostPackages" :disabled="$hostScanRunning">
                                @if ($hostScanRunning)
                                    <x-loading compact />
                                    Scanning…
                                @else
                                    <x-reicon name="refresh" class="size-3.5" />
                                    Scan now
                                @endif
                            </x-forms.button>
                        @endcan
                    @endif
                </x-slot:actions>
            @endif

            @if (! $hostScanAvailable && ! $hostScan)
                <div class="p-4">
                    <x-empty size="sm" icon-name="shield-alert" title="Image scanning is off for {{ $server->name }}."
                        description="Enable image scanning below to also scan the operating system packages of this server.">
                        <x-slot:actions>
                            <a class="text-[13px] font-medium underline" href="#server-image-scanning-section">Image scanning settings</a>
                        </x-slot:actions>
                    </x-empty>
                </div>
            @else
                <div class="flex flex-col gap-3 p-4" @if ($hostScanRunning) wire:poll.10s="loadResult" @endif>
                    <div class="flex flex-wrap items-center gap-2 text-sm text-neutral-600 dark:text-fg-dim">
                        <span>
                            Last scan:
                            @if ($hostScan?->scanned_at)
                                <span title="{{ $hostScan->scanned_at->toDateTimeString() }}">{{ $hostScan->scanned_at->diffForHumans() }}</span>
                            @else
                                never
                            @endif
                        </span>
                        @if ($hostSummary)
                            @if (($hostSummary['medium'] ?? 0) > 0)
                                <x-image-scan.severity-badge severity="medium" :count="$hostSummary['medium']" />
                            @endif
                            @if (($hostSummary['fixable'] ?? 0) > 0)
                                <x-status-badge :status="$hostSummary['fixable'] . ' fixed by an update'" type="warning" />
                            @endif
                        @endif
                    </div>
                    @if ($hostFindings !== [] && ($hostScan->findings_truncated || ! $result))
                        <p class="text-[12px] text-neutral-500 dark:text-fg-faint">
                            @if ($hostScan->findings_truncated)
                                Only the most important vulnerabilities of a larger result are shown.
                            @endif
                            @if (! $result)
                                Run "Check for updates" to see which packages have an update.
                            @endif
                        </p>
                    @endif

                    @if ($hostScanError)
                        <x-callout type="danger" title="The last scan failed">
                            {{ $hostScanError }}
                        </x-callout>
                    @endif
                    @if ($hostScan?->eol)
                        <x-callout type="warning" title="Operating system is end of life">
                            {{ data_get($hostScan->eol, 'name') }} {{ data_get($hostScan->eol, 'version') }} gets no
                            security updates anymore. Upgrade the operating system of this server.
                        </x-callout>
                    @endif
                    @if ($hostScan?->scanned_at && $hostFindings === [])
                        <x-empty size="sm" title="No relevant vulnerabilities"
                            description="The installed packages have no critical, high, or exploited vulnerabilities."
                            icon-name="check-circle" />
                    @endif
                </div>

                @if ($hostFindings !== [] && $shownHostFindings->isEmpty())
                    <div class="border-t border-neutral-200 p-6 dark:border-white/[0.08]">
                        <x-empty size="sm" title="No matching vulnerabilities"
                            description="Try another ID or package, or clear the search." icon-name="search" />
                    </div>
                @elseif ($hostFindings !== [])
                    <div class="data-table">
                        <div class="data-table-header host-vulnerabilities-table-grid">
                            <span>Vulnerability</span>
                            <span>Package</span>
                            <span>Fixed in</span>
                            <span class="text-right">Severity</span>
                        </div>
                        @foreach ($shownHostFindings as $finding)
                            @php
                                $signal = \App\Services\ImageScan\SecurityRules::exploitSignal($finding);
                                $update = $updatesByPackage->get($finding->package);
                                $advisoryUrl = \App\Services\ImageScan\ResourceScans::advisoryUrl($finding->displayId);
                                $likelihood = \App\Services\ImageScan\ResourceScans::exploitLikelihood($finding->epss);
                                $aliases = array_values(array_filter($finding->aliases, fn (string $alias) => $alias !== $finding->displayId));
                            @endphp
                            <div wire:key="host-finding-{{ $finding->id }}-{{ $finding->package }}-{{ $finding->artifactId }}"
                                x-data="{ open: false }"
                                class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                <button type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'"
                                    aria-label="Show details of {{ $finding->displayId }}"
                                    class="data-table-row host-vulnerabilities-table-grid w-full cursor-pointer text-left hover:bg-neutral-50 dark:hover:bg-white/[0.03]">
                                    <div class="flex min-w-0 items-start gap-1.5">
                                        <x-reicon name="chevron-down" class="mt-0.5 size-3.5 shrink-0 text-neutral-500 transition-transform duration-150 dark:text-fg-faint"
                                            ::class="open ? 'rotate-0' : '-rotate-90'" />
                                        <div class="min-w-0">
                                            <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                                <span class="shrink-0 font-mono text-[12px] text-neutral-950 dark:text-fg">{{ $finding->displayId }}</span>
                                                @if ($signal === \App\Services\ImageScan\SecurityRules::EXPLOITED)
                                                    <x-status-badge status="Exploited" type="error" />
                                                @elseif ($signal === \App\Services\ImageScan\SecurityRules::LIKELY)
                                                    <x-status-badge status="Likely exploited" type="warning" />
                                                @endif
                                            </p>
                                            @if (filled($finding->title))
                                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $finding->title }}</p>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate font-mono text-[12px] text-neutral-700 dark:text-fg-dim">{{ $finding->package }}</p>
                                        <p class="mt-0.5 truncate text-[10px] text-neutral-500 dark:text-fg-faint">Installed: {{ $finding->version }}</p>
                                    </div>
                                    <div class="flex min-w-0 flex-col items-start gap-1">
                                        <span class="truncate font-mono text-[12px] text-neutral-700 dark:text-fg-dim">{{ $finding->fixedIn ?? 'No fix yet' }}</span>
                                        @if ($update)
                                            <x-status-badge :status="'Update available: ' . data_get($update, 'new_version')" type="success" />
                                        @endif
                                    </div>
                                    <div class="flex justify-end">
                                        <x-image-scan.severity-badge :severity="$finding->severity" />
                                    </div>
                                </button>
                                <dl x-show="open" x-cloak x-transition.opacity.duration.150ms
                                    class="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-3 gap-y-1.5 px-4 pb-4 sm:grid-cols-[8rem_minmax(0,1fr)] sm:pl-9 text-[12px] text-neutral-700 dark:text-fg-dim">
                                    @if (filled($finding->summary ?: $finding->title))
                                        <dd class="col-span-2 mb-1 max-w-3xl leading-5">{{ $finding->summary ?: $finding->title }}</dd>
                                    @endif
                                    <dt class="text-neutral-500 dark:text-fg-faint">Package</dt>
                                    <dd class="min-w-0 break-all font-mono">{{ $finding->package }}</dd>
                                    <dt class="text-neutral-500 dark:text-fg-faint">Installed</dt>
                                    <dd class="min-w-0 break-all font-mono">{{ $finding->version }}</dd>
                                    <dt class="text-neutral-500 dark:text-fg-faint">Fixed in</dt>
                                    <dd class="min-w-0 break-all font-mono">{{ $finding->fixedIn ?? 'No fix yet' }}</dd>
                                    @if ($update)
                                        <dt class="text-neutral-500 dark:text-fg-faint">Update</dt>
                                        <dd class="min-w-0 break-words">
                                            <span class="break-all font-mono">{{ data_get($update, 'new_version') }}</span> is available. Update it in Package updates above.
                                        </dd>
                                    @endif
                                    @if ($finding->cvssScore !== null)
                                        <dt class="text-neutral-500 dark:text-fg-faint">CVSS score</dt>
                                        <dd>{{ number_format($finding->cvssScore, 1) }}</dd>
                                    @endif
                                    @if ($finding->kev)
                                        <dt class="text-neutral-500 dark:text-fg-faint">Exploited</dt>
                                        <dd>Attackers are using this, according to CISA.</dd>
                                    @elseif ($likelihood !== null)
                                        <dt class="text-neutral-500 dark:text-fg-faint">Exploit likelihood</dt>
                                        <dd>{{ $likelihood }}</dd>
                                    @endif
                                    @if ($aliases !== [])
                                        <dt class="text-neutral-500 dark:text-fg-faint">Also known as</dt>
                                        <dd class="min-w-0 break-all font-mono">{{ implode(', ', $aliases) }}</dd>
                                    @endif
                                    @if ($advisoryUrl !== null)
                                        <dt class="text-neutral-500 dark:text-fg-faint">Advisory</dt>
                                        <dd><a href="{{ $advisoryUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium underline underline-offset-4">Open {{ $finding->displayId }}</a></dd>
                                    @endif
                                </dl>
                            </div>
                        @endforeach
                        <x-table-pagination :from="$shownHostFindings->firstItem() ?? 0" :to="$shownHostFindings->lastItem() ?? 0"
                            :total="$shownHostFindings->total()" :current-page="$shownHostFindings->currentPage()"
                            :last-page="$shownHostFindings->lastPage()" wire-target="previousPage,nextPage,perPage"
                            previous-action="previousPage" next-action="nextPage">
                            <x-slot:pageSize>
                                <x-page-size-select model="perPage" livewire
                                    storage-key="coolify.page-size.server-host-vulnerabilities" />
                            </x-slot:pageSize>
                        </x-table-pagination>
                    </div>
                @endif
            @endif
        </x-application.settings-section>
</div>

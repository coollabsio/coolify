<div>
    <div x-data="{ open: @entangle('open').live }" x-cloak>
        {{-- Backdrop --}}
        <div x-show="open" x-transition.opacity class="fixed inset-0 z-40" style="background: rgba(0,0,0,0.4);" @click="open = false"></div>

        {{-- Slide-over --}}
        <aside class="fixed inset-y-0 right-0 z-50 w-full max-w-[680px] flex flex-col rw-panel transition-transform duration-200 ease-out"
            :class="open ? 'translate-x-0' : 'translate-x-full'">

            @if ($selectedUuid)
                {{-- Header --}}
                <div class="flex items-center gap-3 px-6 pt-6 pb-4">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg shrink-0"
                        style="background: var(--color-rw-elevated); border: 1px solid var(--color-rw-border);">
                        <x-railway.resource-icon :type="$icon" size="w-4 h-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="text-[18px] font-semibold text-rw-text truncate">{{ $name }}</div>
                    </div>
                    <button type="button" @click="open = false" class="rw-icon-btn hover:rw-icon-btn-hover">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>

                {{-- Tabs --}}
                @php
                    $tabs = ['deployments' => 'Deployments', 'variables' => 'Variables', 'metrics' => 'Metrics', 'console' => 'Console', 'settings' => 'Settings'];
                @endphp
                <div class="flex items-center gap-5 px-6 border-b" style="border-color: var(--color-rw-border);">
                    @foreach ($tabs as $key => $label)
                        <button type="button" wire:click="setTab('{{ $key }}')"
                            class="relative pb-2.5 text-[13px] font-medium {{ $tab === $key ? 'text-rw-text' : 'text-rw-subtle hover:text-rw-muted' }}">
                            {{ $label }}
                            @if ($tab === $key)
                                <span class="absolute left-0 -bottom-px w-full h-0.5 rounded-full" style="background: var(--color-rw-text);"></span>
                            @endif
                        </button>
                    @endforeach
                </div>

                {{-- Content --}}
                <div class="flex-1 min-h-0 overflow-y-auto scrollbar px-6 py-5">
                    @switch($tab)
                        @case('deployments')
                            @if (! empty($fqdns))
                                <div class="flex flex-wrap items-center gap-2 mb-4">
                                    @foreach ($fqdns as $fqdn)
                                        <a href="{{ $fqdn }}" target="_blank" rel="noopener" class="rw-pill hover:text-rw-text">
                                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>
                                            {{ preg_replace('#^https?://#', '', $fqdn) }}
                                        </a>
                                    @endforeach
                                </div>
                            @endif

                            @forelse ($deployments as $d)
                                @php
                                    $isActive = $activeDeploymentId && $d['id'] === $activeDeploymentId;
                                    [$badgeLabel, $badgeClass] = match (true) {
                                        $isActive => ['ACTIVE', 'text-rw-online'],
                                        $d['status'] === 'finished' => ['Success', 'text-rw-online'],
                                        $d['status'] === 'failed' => ['Failed', 'text-rw-danger'],
                                        $d['status'] === 'in_progress' => ['Building', 'text-warning'],
                                        $d['status'] === 'queued' => ['Queued', 'text-rw-muted'],
                                        default => ['Cancelled', 'text-rw-subtle'],
                                    };
                                @endphp
                                <div class="rw-node hover:rw-node-hover !flex-row items-center gap-3 mb-2 {{ $isActive ? 'rw-node-selected' : '' }}">
                                    <span class="text-[10px] font-bold uppercase tracking-wide {{ $badgeClass }} w-16 shrink-0">{{ $badgeLabel }}</span>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-[13px] text-rw-text truncate">{{ $d['message'] }}</div>
                                        <div class="text-[11px] text-rw-subtle truncate">
                                            {{ $d['ago'] }} {{ $d['via'] }}@if ($d['commit']) · {{ $d['commit'] }}@endif
                                        </div>
                                    </div>
                                    <button type="button" wire:click="openDeploymentLogs({{ $d['id'] }})" class="rw-btn hover:rw-btn-hover !h-7 !px-2.5 !text-[12px] shrink-0">View logs</button>
                                </div>
                            @empty
                                <div class="text-[13px] text-rw-subtle py-8 text-center">No deployments recorded for this resource.</div>
                            @endforelse

                            @if (! empty($links['deployments']))
                                <a href="{{ $links['deployments'] }}" wire:navigate class="inline-flex mt-2 text-[12px] text-rw-accent hover:underline">View all deployments →</a>
                            @endif
                            @break

                        @case('variables')
                            {{-- Real Coolify environment-variable editor, embedded inline (add/edit/reveal/delete). --}}
                            @if ($resource)
                                <div class="rw-embed">
                                    <livewire:project.shared.environment-variable.all :resource="$resource" :key="'rw-vars-'.$selectedUuid" />
                                </div>
                            @endif
                            @break

                        @case('metrics')
                            {{-- Real Coolify metrics (CPU/Memory ApexCharts), embedded inline. --}}
                            @if ($resource)
                                <div class="rw-embed">
                                    <livewire:project.shared.metrics :resource="$resource" :chartId="'rwm-'.$selectedUuid" :key="'rw-metrics-'.$selectedUuid" />
                                </div>
                            @endif
                            @break

                        @case('console')
                            <div class="flex flex-col items-center justify-center gap-3 py-16 text-center">
                                <svg class="w-10 h-10 text-rw-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m6 9 3 3-3 3M13 15h4"/></svg>
                                <div class="text-[13px] text-rw-muted">Open an interactive shell into the container</div>
                                @if (! empty($links['terminal']))
                                    <a href="{{ $links['terminal'] }}" wire:navigate class="rw-btn hover:rw-btn-hover">Open terminal</a>
                                @endif
                            </div>
                            @break

                        @case('settings')
                            @php
                                $rwInput = 'w-full rounded-md border px-3 h-9 text-[13px] text-rw-text bg-transparent focus:outline-none placeholder:text-rw-subtle';
                                $rwInputStyle = 'border-color: var(--color-rw-border); background: var(--color-rw-elevated);';
                            @endphp
                            <div class="max-w-2xl">
                                {{-- Filter (decorative, matches Railway) --}}
                                <div class="flex items-center gap-2 mb-6 rounded-md border px-3 h-9" style="{{ $rwInputStyle }}">
                                    <svg class="w-4 h-4 text-rw-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                                    <input type="text" placeholder="Filter Settings…" class="flex-1 bg-transparent text-[13px] text-rw-text placeholder:text-rw-subtle focus:outline-none border-0 p-0" />
                                    <kbd class="text-[11px] text-rw-subtle rounded border px-1.5" style="border-color: var(--color-rw-border);">/</kbd>
                                </div>

                                {{-- Source (applications) --}}
                                @if ($isApplication)
                                    <section class="mb-8">
                                        <div class="flex items-center gap-2.5 mb-4">
                                            <svg class="w-4 h-4 text-rw-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/></svg>
                                            <h3 class="text-[16px] font-semibold text-rw-text">Source</h3>
                                        </div>
                                        <label class="block text-[12px] text-rw-muted mb-1.5">Source Repo</label>
                                        <div class="rw-node !flex-row items-center gap-2 !py-2.5 mb-4">
                                            <svg class="w-4 h-4 text-rw-text shrink-0" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/></svg>
                                            <span class="text-[13px] font-mono text-rw-text truncate flex-1">{{ $settingsRepo ?: 'No repository connected' }}</span>
                                        </div>
                                        <label class="block text-[12px] text-rw-muted mb-1.5">Branch connected to {{ $environment->name }}</label>
                                        <input type="text" wire:model="settingsBranch" placeholder="main" class="{{ $rwInput }} mb-4" style="{{ $rwInputStyle }}" />
                                        <div class="rw-node !flex-row items-center justify-between !py-3">
                                            <div>
                                                <div class="text-[13px] text-rw-text">Auto deploy</div>
                                                <div class="text-[11px] text-rw-subtle">Changes pushed to this branch deploy automatically.</div>
                                            </div>
                                            <button type="button" wire:click="toggleAutoDeploy" class="relative w-9 h-5 rounded-full shrink-0 transition-colors" style="background: {{ $settingsAutoDeploy ? 'var(--color-rw-accent)' : 'var(--color-rw-border-strong)' }};">
                                                <span class="absolute top-0.5 w-4 h-4 rounded-full bg-white transition-all" style="left: {{ $settingsAutoDeploy ? '18px' : '2px' }};"></span>
                                            </button>
                                        </div>
                                    </section>
                                @endif

                                {{-- General & Networking --}}
                                <section class="mb-8">
                                    <div class="flex items-center gap-2.5 mb-4">
                                        <svg class="w-4 h-4 text-rw-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18"/></svg>
                                        <h3 class="text-[16px] font-semibold text-rw-text">General &amp; Networking</h3>
                                    </div>
                                    <label class="block text-[12px] text-rw-muted mb-1.5">Name</label>
                                    <input type="text" wire:model="settingsName" class="{{ $rwInput }} mb-1" style="{{ $rwInputStyle }}" />
                                    @error('settingsName') <div class="text-[12px] text-rw-danger mb-2">{{ $message }}</div> @enderror
                                    <label class="block text-[12px] text-rw-muted mb-1.5 mt-3">Description</label>
                                    <input type="text" wire:model="settingsDescription" placeholder="Optional" class="{{ $rwInput }} mb-4" style="{{ $rwInputStyle }}" />
                                    <label class="block text-[12px] text-rw-muted mb-1.5">Public Domains</label>
                                    <input type="text" wire:model="settingsDomains" placeholder="https://app.example.com, https://www.example.com" class="{{ $rwInput }}" style="{{ $rwInputStyle }}" />
                                    <div class="text-[11px] text-rw-subtle mt-1">Comma-separate multiple domains.</div>
                                </section>

                                {{-- Build (applications) --}}
                                @if ($isApplication)
                                    <section class="mb-8">
                                        <div class="flex items-center gap-2.5 mb-4">
                                            <svg class="w-4 h-4 text-rw-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                                            <h3 class="text-[16px] font-semibold text-rw-text">Build &amp; Deploy</h3>
                                        </div>
                                        <label class="block text-[12px] text-rw-muted mb-1.5">Base Directory</label>
                                        <input type="text" wire:model="settingsBaseDirectory" placeholder="/" class="{{ $rwInput }} mb-4" style="{{ $rwInputStyle }}" />
                                        <label class="block text-[12px] text-rw-muted mb-1.5">Install Command</label>
                                        <input type="text" wire:model="settingsInstallCommand" placeholder="npm install" class="{{ $rwInput }} mb-4 font-mono" style="{{ $rwInputStyle }}" />
                                        <label class="block text-[12px] text-rw-muted mb-1.5">Build Command</label>
                                        <input type="text" wire:model="settingsBuildCommand" placeholder="npm run build" class="{{ $rwInput }} mb-4 font-mono" style="{{ $rwInputStyle }}" />
                                        <label class="block text-[12px] text-rw-muted mb-1.5">Start Command</label>
                                        <input type="text" wire:model="settingsStartCommand" placeholder="npm start" class="{{ $rwInput }} font-mono" style="{{ $rwInputStyle }}" />
                                    </section>
                                @endif

                                <button type="button" wire:click="saveSettings" class="rw-btn-primary hover:rw-btn-primary-hover">Save changes</button>

                                {{-- Danger --}}
                                <section class="mt-10 pt-6 border-t" style="border-color: var(--color-rw-border);">
                                    <div class="flex items-center gap-2.5 mb-2">
                                        <svg class="w-4 h-4 text-rw-danger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01"/></svg>
                                        <h3 class="text-[16px] font-semibold text-rw-danger">Danger</h3>
                                    </div>
                                    <div class="text-[13px] text-rw-subtle mb-3">Permanently delete this resource, its containers and volumes. This can't be undone.</div>
                                    <button type="button" x-data
                                        @click="confirm('Delete {{ addslashes($name) }}? This permanently removes the container and its data.') && $wire.deleteResource()"
                                        class="inline-flex items-center rounded-md px-3 h-9 text-[13px] font-medium text-white" style="background: var(--color-rw-danger);">
                                        Delete resource
                                    </button>
                                </section>
                            </div>
                            @break
                    @endswitch
                </div>
            @endif
        </aside>

        {{-- Per-deployment logs popup (Railway-style) --}}
        @if ($deploymentDetail)
            @php
                $statusMap = [
                    'finished' => ['Active', 'text-rw-online'],
                    'failed' => ['Failed', 'text-rw-danger'],
                    'in_progress' => ['Building', 'text-warning'],
                    'queued' => ['Queued', 'text-rw-muted'],
                ];
                [$dStatusLabel, $dStatusClass] = $statusMap[$deploymentDetail['status']] ?? ['Cancelled', 'text-rw-subtle'];
                $logTabs = ['details' => 'Details', 'build' => 'Build Logs', 'deploy' => 'Deploy Logs', 'http' => 'HTTP Logs', 'network' => 'Network Flow Logs'];
            @endphp
            <div class="fixed inset-0 z-[60] flex">
                <div class="flex-1" wire:click="closeDeploymentLogs"></div>
                <aside class="w-full max-w-[1040px] flex flex-col border-l" style="border-color: var(--color-rw-border); background: var(--color-rw-surface);">
                    {{-- Header --}}
                    <div class="px-6 pt-6 pb-3">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg shrink-0" style="background: var(--color-rw-elevated); border: 1px solid var(--color-rw-border);">
                                <x-railway.resource-icon :type="$icon" size="w-4 h-4" />
                            </span>
                            <span class="text-[15px] font-semibold text-rw-text">{{ $name }}</span>
                            <span class="text-rw-subtle">/</span>
                            <span class="text-[13px] font-mono text-rw-muted">{{ $deploymentDetail['commit'] ?? substr((string) $deploymentDetail['uuid'], 0, 7) }}</span>
                            <span class="text-[11px] font-semibold {{ $dStatusClass }} border rounded px-1.5 py-0.5" style="border-color: var(--color-rw-border);">{{ $dStatusLabel }}</span>
                            <div class="flex-1"></div>
                            <button type="button" class="rw-icon-btn hover:rw-icon-btn-hover" title="More">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>
                            </button>
                            @if ($deploymentDetail['created_at'])
                                <span class="text-[12px] text-rw-subtle whitespace-nowrap">{{ $deploymentDetail['created_at'] }}</span>
                            @endif
                            <button type="button" wire:click="closeDeploymentLogs" class="rw-icon-btn hover:rw-icon-btn-hover">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                            </button>
                        </div>
                        @if (! empty($fqdns))
                            <div class="mt-1.5 ml-10 text-[13px] text-rw-muted">{{ preg_replace('#^https?://#', '', $fqdns[0]) }}</div>
                        @endif
                    </div>

                    {{-- Tabs --}}
                    <div class="flex items-center gap-5 px-6 border-b" style="border-color: var(--color-rw-border);">
                        @foreach ($logTabs as $k => $label)
                            <button type="button" wire:click="setDeploymentLogTab('{{ $k }}')"
                                class="relative pb-2.5 text-[13px] font-medium {{ $deploymentLogTab === $k ? 'text-rw-text' : 'text-rw-subtle hover:text-rw-muted' }}">
                                {{ $label }}
                                @if ($deploymentLogTab === $k)
                                    <span class="absolute left-0 -bottom-px w-full h-0.5 rounded-full" style="background: var(--color-rw-text);"></span>
                                @endif
                            </button>
                        @endforeach
                    </div>

                    {{-- Content --}}
                    <div class="flex-1 min-h-0 overflow-y-auto scrollbar px-6 py-4">
                        @switch($deploymentLogTab)
                            @case('details')
                                <div class="flex flex-col gap-3 max-w-xl">
                                    <div>
                                        <div class="text-[11px] font-semibold text-rw-subtle uppercase tracking-wide mb-1">Commit</div>
                                        <div class="text-[13px] text-rw-text">{{ $deploymentDetail['message'] }}</div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div><div class="text-[11px] text-rw-subtle mb-0.5">Status</div><div class="text-[13px] {{ $dStatusClass }}">{{ $dStatusLabel }}</div></div>
                                        <div><div class="text-[11px] text-rw-subtle mb-0.5">Source</div><div class="text-[13px] text-rw-text">{{ $deploymentDetail['via'] }}</div></div>
                                        <div><div class="text-[11px] text-rw-subtle mb-0.5">Started</div><div class="text-[13px] text-rw-text">{{ $deploymentDetail['created_at'] ?? '—' }}</div></div>
                                        <div><div class="text-[11px] text-rw-subtle mb-0.5">Finished</div><div class="text-[13px] text-rw-text">{{ $deploymentDetail['finished_at'] ?? '—' }}</div></div>
                                        <div><div class="text-[11px] text-rw-subtle mb-0.5">Server</div><div class="text-[13px] text-rw-text">{{ $deploymentDetail['server'] ?? '—' }}</div></div>
                                        <div><div class="text-[11px] text-rw-subtle mb-0.5">Deployment ID</div><div class="text-[13px] font-mono text-rw-muted truncate">{{ substr((string) $deploymentDetail['uuid'], 0, 12) }}</div></div>
                                    </div>
                                </div>
                                @break

                            @case('build')
                            @case('deploy')
                                <div x-data="{ q: '' }">
                                    {{-- Search + actions --}}
                                    <div class="flex items-center gap-2 mb-3">
                                        <div class="flex items-center gap-2 flex-1 rounded-md border px-3 h-9" style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);">
                                            <svg class="w-4 h-4 text-rw-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                                            <input type="text" x-model="q" placeholder="Filter and search logs" class="flex-1 bg-transparent text-[13px] text-rw-text placeholder:text-rw-subtle focus:outline-none border-0 p-0" />
                                            <kbd class="text-[11px] text-rw-subtle rounded border px-1.5" style="border-color: var(--color-rw-border);">/</kbd>
                                        </div>
                                        <button type="button" class="rw-icon-btn hover:rw-icon-btn-hover border" style="border-color: var(--color-rw-border);" title="Download">
                                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 21h16"/></svg>
                                        </button>
                                        <button type="button" class="rw-icon-btn hover:rw-icon-btn-hover border" style="border-color: var(--color-rw-border);" title="Open external">
                                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M8 7h9v9"/></svg>
                                        </button>
                                    </div>
                                    {{-- Column headers --}}
                                    <div class="flex items-center pb-2 border-b text-[11px] font-medium text-rw-subtle" style="border-color: var(--color-rw-border);">
                                        <span class="w-48 pl-3.5">Time (PDT)</span>
                                        <span class="flex-1">Data</span>
                                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                    </div>
                                    @if (count($deploymentDetail['lines']))
                                        <div class="flex justify-center my-3">
                                            <span class="inline-flex items-center gap-2 text-[11px] text-rw-subtle rounded-md border px-2.5 py-1" style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);">
                                                You reached the start of the range
                                                <span class="text-rw-muted">→ {{ $deploymentDetail['created_at'] }}</span>
                                            </span>
                                        </div>
                                        <div class="font-mono text-[12px] leading-relaxed">
                                            @foreach ($deploymentDetail['lines'] as $ln)
                                                @php $isErr = $ln['type'] === 'stderr' || str_starts_with(ltrim((string) $ln['line']), '$'); @endphp
                                                <div class="flex items-stretch hover:bg-white/[0.02] {{ $isErr ? 'bg-red-500/[0.06]' : '' }}"
                                                    data-line="{{ strtolower($ln['ts'].' '.$ln['line']) }}"
                                                    x-show="!q || $el.dataset.line.includes(q.toLowerCase())">
                                                    <span class="w-[3px] shrink-0" style="background: {{ $isErr ? 'var(--color-rw-danger)' : '#3b6ef5' }};"></span>
                                                    <span class="text-rw-subtle shrink-0 w-48 pl-3 py-0.5 select-none">{{ $ln['ts'] }}</span>
                                                    <span class="flex-1 py-0.5 pr-2 whitespace-pre-wrap break-all {{ $isErr ? 'text-rw-danger' : 'text-rw-text' }}">{{ $ln['line'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="text-[13px] text-rw-subtle py-10 text-center">No logs recorded for this deployment.</div>
                                    @endif
                                </div>
                                @break

                            @default
                                <div class="flex flex-col items-center justify-center gap-2 py-16 text-center">
                                    <div class="text-[14px] text-rw-muted">{{ $logTabs[$deploymentLogTab] }}</div>
                                    <div class="text-[12px] text-rw-subtle max-w-sm">Coolify doesn't record {{ strtolower($logTabs[$deploymentLogTab]) }} per deployment. Container logs are available in the resource's Logs tab.</div>
                                </div>
                        @endswitch
                    </div>
                </aside>
            </div>
        @endif
    </div>
</div>

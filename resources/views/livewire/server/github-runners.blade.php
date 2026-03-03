<div wire:init="initializeRepositories">
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > GitHub Runners | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="github-runners" />
        <div class="w-full">
            <div class="flex items-center gap-2">
                <h2>GitHub Actions Runners</h2>
                @if ($this->config)
                    <x-forms.button wire:click="toggleEnabled" canGate="update" :canResource="$server">
                        {{ $this->config->is_enabled ? 'Disable' : 'Enable' }}
                    </x-forms.button>
                @endif
            </div>
            <div class="mt-1 mb-6">Use this server as a GitHub Actions self-hosted runner host. Runners are ephemeral (JIT) — spun up per workflow job and cleaned up automatically.</div>

            {{-- Permission Warning --}}
            @if ($selectedGithubAppId && $this->selectedAppHasRunnerPermission === false)
                <div class="mb-4">
                    <x-callout type="warning" title="Missing Permission">
                        <p>The selected GitHub App does not have the <code>organization_self_hosted_runners: write</code> permission.</p>
                        <p class="mt-1">
                            1. Add it in your <a href="{{ getPermissionsPath($this->selectedApp) }}" target="_blank" class="underline">GitHub App settings</a>,
                            then 2. re-sync permissions in <a href="{{ route('source.github.show', ['github_app_uuid' => $this->selectedApp->uuid]) }}" class="underline">Coolify's Source settings</a>.
                        </p>
                    </x-callout>
                </div>
            @endif

            {{-- Accessible Repositories --}}
            @if ($selectedGithubAppId && $this->selectedApp && !$this->selectedApp->is_public)
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-sm font-medium">Accessible Repositories</label>
                        <div class="flex items-center gap-3">
                            <button type="button" wire:click="loadAccessibleRepositories"
                                class="text-xs text-neutral-400 hover:text-white transition-colors">
                                Refresh
                            </button>
                            <a href="{{ getInstallationPath($this->selectedApp) }}" target="_blank"
                                class="text-xs text-warning hover:underline">
                                Manage Accessible Repositories →
                            </a>
                        </div>
                    </div>

                    <div x-data="{
                        open: false,
                        search: '',
                        get repos() {
                            return $wire.accessibleRepositories ?? [];
                        },
                        get filtered() {
                            if (!this.search) return this.repos;
                            const q = this.search.toLowerCase();
                            return this.repos.filter(r => r.toLowerCase().includes(q));
                        }
                    }" @click.outside="open = false" class="relative mt-1">
                        <div @click="open = !open"
                            class="flex items-center gap-2 w-full input cursor-pointer">
                            <input type="text" x-model="search" @click.stop @focus="open = true"
                                @keydown.escape="open = false"
                                placeholder="{{ $repositoriesLoading || ! $repositoriesLoaded ? 'Loading repositories...' : count($accessibleRepositories).' '.Str::plural('repository', count($accessibleRepositories)).' accessible — type to search...' }}"
                                class="flex-1 text-sm border-0 outline-none bg-transparent px-2 py-0 focus:ring-0 placeholder:text-neutral-400 dark:placeholder:text-neutral-600 text-white" />
                            <svg class="w-4 h-4 shrink-0 mr-2.5 duration-200 ease-out text-neutral-500"
                                :class="{ 'rotate-180': open }" viewBox="0 0 24 24"
                                xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </div>
                        <div x-show="open" x-transition
                            class="absolute z-50 w-full mt-1 bg-coolgray-100 border border-coolgray-400 rounded shadow-lg max-h-60 overflow-auto scrollbar">
                            <template x-if="filtered.length === 0">
                                <div class="px-3 py-2 text-sm text-neutral-400">No matching repositories</div>
                            </template>
                            <template x-for="repo in filtered" :key="repo">
                                <div class="px-3 py-2 text-sm font-mono text-neutral-300" x-text="repo"></div>
                            </template>
                        </div>
                    </div>

                    @if ($repositoryError)
                        <x-callout type="error" title="Could Not Load Repositories">
                            {{ $repositoryError }}
                        </x-callout>
                    @elseif ($repositoriesLoaded && count($accessibleRepositories) === 0)
                        <x-callout type="warning" title="No Repositories Loaded">
                            <p>No repositories are accessible yet, or the GitHub App is set to "All repositories" (all org repos are covered automatically).</p>
                            <p class="mt-1">If you expect specific repositories to appear, <a href="{{ getInstallationPath($this->selectedApp) }}" target="_blank" class="underline">manage accessible repositories</a> in your GitHub App settings.</p>
                        </x-callout>
                    @endif
                </div>
            @endif

            {{-- Configuration Form --}}
            <form wire:submit="submit">
                <div class="flex flex-col gap-4">
                    <div>
                        <label for="selectedGithubAppId" class="block text-sm font-medium">GitHub App</label>
                        <select wire:model.live="selectedGithubAppId" id="selectedGithubAppId"
                            class="w-full mt-1 input">
                            <option value="">Select a GitHub App...</option>
                            @foreach ($this->githubApps as $app)
                                <option value="{{ $app->id }}">
                                    {{ $app->name }}
                                    @if ($app->organization)
                                        ({{ $app->organization }})
                                    @endif
                                    @if ($app->organization_self_hosted_runners === 'write')
                                        ✓ Runner Permission
                                    @endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex gap-4">
                        <x-forms.input canGate="update" :canResource="$server" id="runnerGroupName"
                            label="Runner Group Name (optional)"
                            helper="If set, Coolify will enforce this GitHub organization runner group name during sync." />
                    </div>

                    <div class="flex gap-4">
                        <x-forms.input canGate="update" :canResource="$server" id="labels"
                            label="Labels (comma-separated)" required
                            helper="Labels for routing workflow jobs to this server. Workflows use runs-on to match these labels." />
                    </div>

                    <div class="flex gap-2">
                        <x-forms.input canGate="update" :canResource="$server" id="maxRunners" type="number"
                            label="Max Concurrent Runners" required
                            helper="Maximum number of runners that can run simultaneously on this server." />
                        <x-forms.input canGate="update" :canResource="$server" id="capacityWaitTimeout" type="number"
                            label="Capacity Wait Timeout (minutes)" required
                            helper="How long queued jobs wait for a runner slot to become available before giving up. Default: 60 minutes (1 hour)." />
                    </div>

                    <div class="flex gap-4">
                        <x-forms.input canGate="update" :canResource="$server" id="runnerUser"
                            label="Runner User" required
                            helper="Linux user to run the runner process as. Will be created if it doesn't exist." />
                    </div>

                    <div class="flex gap-2">
                        <x-forms.input canGate="update" :canResource="$server" id="runnerBaseDir"
                            label="Base Directory" required
                            helper="Directory on the server where runner binaries and working directories will be stored." />
                        <x-forms.input canGate="update" :canResource="$server" id="runnerVersion"
                            label="Runner Version (optional)"
                            helper="Pin to a specific runner version (e.g. 2.321.0). Leave empty for latest." />
                    </div>

                    <div class="flex items-center gap-2 mt-2">
                        <x-forms.button type="submit" canGate="update" :canResource="$server">Save</x-forms.button>
                        @if ($this->config)
                            <x-modal-confirmation title="Delete Runner Configuration?" buttonTitle="Delete Configuration"
                                submitAction="deleteConfig"
                                :actions="['This will remove the runner configuration from this server.', 'Active runners will not be affected until they complete.']"
                                :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Delete Configuration" />
                        @endif
                    </div>
                </div>
            </form>

            {{-- Status --}}
            @if ($this->config)
                <div class="mt-8">
                    <h3 class="mb-2">Status</h3>
                    <div class="flex items-center gap-4 text-sm">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full {{ $this->config->is_enabled ? 'bg-success' : 'bg-error' }}"></span>
                            {{ $this->config->is_enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                        <span>Active Runners: {{ $this->activeRunnerCount }} / {{ $this->config->max_runners }}</span>
                        <span>Organization: {{ $this->config->githubApp?->organization }}</span>
                        <span>Labels: {{ implode(', ', $this->config->labels ?? []) }}</span>
                    </div>
                </div>
            @endif

            @if ($this->config)
                <livewire:server.github-runner-executions :server="$server" />
            @endif
        </div>
    </div>
</div>

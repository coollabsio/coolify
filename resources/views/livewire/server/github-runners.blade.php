<div>
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
                                Manage Repository Access →
                            </a>
                        </div>
                    </div>

                    @if ($repositoryError)
                        <x-callout type="error" title="Could Not Load Repositories">
                            {{ $repositoryError }}
                        </x-callout>
                    @elseif (count($accessibleRepositories) === 0)
                        <x-callout type="warning" title="No Repositories Loaded">
                            <p>No repositories are accessible yet, or the GitHub App is set to "All repositories" (all org repos are covered automatically).</p>
                            <p class="mt-1">If you expect specific repositories to appear, <a href="{{ getInstallationPath($this->selectedApp) }}" target="_blank" class="underline">manage repository access</a> in your GitHub App settings.</p>
                        </x-callout>
                    @else
                        <div x-data="{
                            open: false,
                            search: '',
                            repos: @js($accessibleRepositories),
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
                                    placeholder="{{ count($accessibleRepositories) }} {{ Str::plural('repository', count($accessibleRepositories)) }} accessible — type to search..."
                                    class="flex-1 text-sm border-0 outline-none bg-transparent px-2 py-0 focus:ring-0 placeholder:text-neutral-400 dark:placeholder:text-neutral-600 text-white" />
                                <svg class="w-4 h-4 shrink-0 text-neutral-400 transition-transform" :class="{ 'rotate-180': open }" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
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
                    @endif
                    <span wire:loading wire:target="loadAccessibleRepositories" class="text-xs text-neutral-400 mt-1 inline-block">Loading...</span>
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
                        <x-forms.input canGate="update" :canResource="$server" id="labels"
                            label="Labels (comma-separated)" required
                            helper="Labels for routing workflow jobs to this server. Workflows use runs-on to match these labels." />
                    </div>

                    <div class="flex gap-4">
                        <x-forms.input canGate="update" :canResource="$server" id="maxRunners" type="number"
                            label="Max Concurrent Runners" required
                            helper="Maximum number of runners that can run simultaneously on this server." />
                        <x-forms.input canGate="update" :canResource="$server" id="runnerUser"
                            label="Runner User" required
                            helper="Linux user to run the runner process as. Will be created if it doesn't exist." />
                    </div>

                    <div class="flex gap-4">
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
                            <x-forms.button wire:click="preinstallBinary" canGate="update" :canResource="$server">
                                Pre-install Binary
                            </x-forms.button>
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

            {{-- Executions --}}
            @if ($this->config)
                <div class="mt-8" wire:poll.10s>
                    <h3 class="mb-4">Recent Executions</h3>
                    @if ($this->recentExecutions->isEmpty())
                        <div class="text-sm text-neutral-500">No runner executions yet. When a workflow job matches this server's labels, it will appear here.</div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left border-b border-neutral-700">
                                        <th class="pb-2 pr-4">Runner</th>
                                        <th class="pb-2 pr-4">Workflow</th>
                                        <th class="pb-2 pr-4">Repository</th>
                                        <th class="pb-2 pr-4">Status</th>
                                        <th class="pb-2 pr-4">Duration</th>
                                        <th class="pb-2 pr-4">Started</th>
                                        <th class="pb-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->recentExecutions as $execution)
                                        <tr class="border-b border-neutral-800">
                                            <td class="py-2 pr-4 font-mono text-xs">{{ $execution->runner_name }}</td>
                                            <td class="py-2 pr-4">{{ $execution->workflow_name ?? '-' }}</td>
                                            <td class="py-2 pr-4">{{ $execution->repository_full_name ?? '-' }}</td>
                                            <td class="py-2 pr-4">
                                                @switch($execution->status->value)
                                                    @case('queued')
                                                        <span class="px-2 py-0.5 text-xs rounded-full bg-warning/20 text-warning">Queued</span>
                                                        @break
                                                    @case('provisioning')
                                                        <span class="px-2 py-0.5 text-xs rounded-full bg-blue-500/20 text-blue-400">Provisioning</span>
                                                        @break
                                                    @case('running')
                                                        <span class="px-2 py-0.5 text-xs rounded-full bg-success/20 text-success">Running</span>
                                                        @break
                                                    @case('completed')
                                                        <span class="px-2 py-0.5 text-xs rounded-full bg-neutral-500/20 text-neutral-400">Completed</span>
                                                        @break
                                                    @case('failed')
                                                        <span class="px-2 py-0.5 text-xs rounded-full bg-error/20 text-error">Failed</span>
                                                        @break
                                                    @case('timed_out')
                                                        <span class="px-2 py-0.5 text-xs rounded-full bg-orange-500/20 text-orange-400">Timed Out</span>
                                                        @break
                                                    @case('cleaning')
                                                        <span class="px-2 py-0.5 text-xs rounded-full bg-blue-500/20 text-blue-400">Cleaning</span>
                                                        @break
                                                @endswitch
                                            </td>
                                            <td class="py-2 pr-4">{{ $execution->duration() ?? '-' }}</td>
                                            <td class="py-2 pr-4">{{ $execution->started_at?->diffForHumans() ?? $execution->created_at->diffForHumans() }}</td>
                                            <td class="py-2">
                                                @if ($execution->isActive())
                                                    <x-forms.button wire:click="cancelExecution({{ $execution->id }})"
                                                        wire:confirm="Cancel this runner? This will kill the process, remove the runner directory, and deregister from GitHub."
                                                        canGate="update" :canResource="$server"
                                                        class="!py-0.5 !px-2 !text-xs">
                                                        Cancel
                                                    </x-forms.button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>

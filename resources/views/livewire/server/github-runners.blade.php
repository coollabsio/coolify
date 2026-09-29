<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > GitHub Runners | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="github-runners" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            @if (!$server->isBuildServer())
                <x-application.settings-section id="github-runners-section" title="GitHub Actions runners"
                    helper="Each workflow job runs in a new container that is deleted after the job.">
                    <x-slot:actions>
                        <x-beta-badge />
                        <a class="button" href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}"
                            {{ wireNavigate() }}>
                            General settings
                            <x-external-link />
                        </a>
                    </x-slot:actions>
                    <x-empty size="sm" title="This server is not enabled for builds"
                        description="GitHub Actions runners only run on servers with the Build role. Change the server role in the General settings."
                        icon-name="play-circle" />
                </x-application.settings-section>
            @elseif ($this->githubApps->isEmpty())
                <x-callout type="info" title="No organization GitHub App">
                    Runners are registered at organization level. Add a GitHub App that belongs to an organization
                    under Sources, and select "Run workflow jobs on build servers" when you register it.
                </x-callout>
            @elseif (! $this->config?->is_enabled)
                <x-application.settings-section id="github-runners-section" title="GitHub Actions runners"
                    helper="Each workflow job runs in a new container that is deleted after the job.">
                    <x-slot:actions>
                        <x-beta-badge />
                    </x-slot:actions>
                    <x-empty size="sm" title="Runners are disabled"
                        description="Enable runners to take GitHub Actions workflow jobs on this server."
                        icon-name="play-circle">
                        <x-slot:contents>
                            <x-forms.button canGate="update" :canResource="$server" isHighlighted
                                wire:click="toggleEnabled" wire:loading.attr="disabled" wire:target="toggleEnabled">
                                Enable runners
                            </x-forms.button>
                        </x-slot:contents>
                    </x-empty>
                </x-application.settings-section>
            @else
                <form wire:submit="submit" class="contents">
                    <x-unsaved-bar action="submit"
                        targets="githubAppId,labels,maxRunners,dockerMode,runnerImage,cpuLimit,memoryLimit,capacityWaitTimeout,idleTimeout,jobTimeout,isDedicated" />
                    <x-application.settings-section id="github-runners-section" title="GitHub Actions runners"
                        helper="Each workflow job runs in a new container that is deleted after the job. Use runs-on: [self-hosted, <your label>] in the workflow.">
                        <x-slot:actions>
                            <x-beta-badge />
                            @can('update', $server)
                                <x-forms.button wire:click="toggleEnabled" wire:loading.attr="disabled"
                                    wire:target="toggleEnabled">
                                    Disable runners
                                </x-forms.button>
                            @endcan
                        </x-slot:actions>

                        <div x-cloak x-show="$wire.dockerMode === 'dind'">
                            <x-callout type="warning" title="Use only for trusted, private repositories">
                                In the privileged Docker mode, a malicious workflow can take control of this server.
                                Use Isolated Docker (Sysbox) or No Docker to keep jobs away from the server. Coolify
                                limits the runner group to private repositories.
                            </x-callout>
                        </div>

                        @if ($this->selectedApp && $this->selectedApp->missingRunnerRequirements() !== [])
                            <x-callout type="danger" title="The GitHub App is not ready" class="mt-4">
                                Update the App on GitHub, then click Refetch on the App's Permissions page. Missing:
                                <ul class="mt-1 list-disc pl-4">
                                    @foreach ($this->selectedApp->missingRunnerRequirements() as $requirement)
                                        <li>{{ $requirement }}</li>
                                    @endforeach
                                </ul>
                            </x-callout>
                        @endif

                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            <x-forms.listbox id="githubAppId" label="GitHub App" required canGate="update"
                                :canResource="$server" :options="$this->githubApps
                                    ->map(fn($app) => ['value' => $app->id, 'label' => $app->name . ' (' . $app->organization . ')'])
                                    ->values()
                                    ->all()"
                                helper="Organization GitHub App that receives the Workflow job webhook events." />
                            <x-forms.input id="labels" label="Labels" required canGate="update" :canResource="$server"
                                placeholder="coolify"
                                helper="Comma-separated custom labels. Coolify also registers self-hosted and linux. Jobs must ask for at least one custom label." />
                            <x-forms.listbox id="isDedicated" label="Application builds" canGate="update"
                                :canResource="$server" :options="[
                                    ['value' => false, 'label' => 'Also build applications'],
                                    ['value' => true, 'label' => 'Dedicated to runners'],
                                ]"
                                helper="Dedicated servers are not used for application builds while runners are enabled." />
                        </div>
                    </x-application.settings-section>

                    <x-application.settings-section id="github-runners-resources-section" title="Resources"
                        helper="Limits apply to the runner container and to its Docker sidecar.">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.input id="maxRunners" type="number" min="1" max="32" label="Parallel runners"
                                required canGate="update" :canResource="$server"
                                helper="Jobs wait in the queue when all runners are busy." />
                            <x-forms.input id="cpuLimit" label="CPU limit" placeholder="2" canGate="update"
                                :canResource="$server" helper="Number of CPUs for each runner. Empty means no limit." />
                            <x-forms.input id="memoryLimit" label="Memory limit" placeholder="4g" canGate="update"
                                :canResource="$server" helper="Memory for each runner, for example 4g. Empty means no limit." />
                            <x-forms.listbox id="dockerMode" label="Docker in jobs" canGate="update"
                                :canResource="$server" :options="[
                                    ['value' => 'dind', 'label' => 'Docker (privileged, full host access)'],
                                    ['value' => 'sysbox', 'label' => 'Isolated Docker (Sysbox)'],
                                    ['value' => 'none', 'label' => 'No Docker (isolated, no container actions)'],
                                ]"
                                helper="Docker: each job gets a private Docker daemon. The daemon runs privileged, so a job can get root access to this server. Use it only for trusted repositories.<br><br>Isolated Docker: each job gets a private Docker daemon in an unprivileged Sysbox container. Everything that needs Docker works, and root in the job has no rights on this server. Needs Sysbox on the server.<br><br>No Docker: jobs run in an unprivileged container. JavaScript actions, composite actions, and shell steps work. Docker container actions, services, container jobs, and docker commands fail.<br><br>The host Docker socket is never shared." />
                            <x-forms.input id="runnerImage" label="Runner image" canGate="update" :canResource="$server"
                                :placeholder="config('constants.github_runner.image')"
                                helper="Empty uses the latest official runner image, which Coolify pulls for every runner. Set a tag, for example ghcr.io/actions/actions-runner:2.337.0, to pin a version, or use a custom image based on the official one to add tools. GitHub stops sending jobs to runners that are more than 30 days old." />
                        </div>
                        <div x-cloak x-show="$wire.dockerMode === 'sysbox'" class="mt-4"
                            x-effect="if ($wire.dockerMode === 'sysbox' && $wire.isSysboxInstalled === null) $wire.checkSysbox()">
                            @if ($isSysboxInstalled === true)
                                <x-callout type="success" title="Sysbox is installed">
                                    Jobs get a private Docker daemon in an unprivileged container.
                                </x-callout>
                            @elseif ($isSysboxInstalled === false)
                                <x-callout type="warning" title="Sysbox is not installed">
                                    Isolated Docker needs the Sysbox runtime. Coolify installs Sysbox
                                    {{ config('constants.github_runner.sysbox.version') }} from the official package
                                    (Debian and Ubuntu, kernel 5.12 or newer). Docker reloads its configuration, and
                                    running containers are not restarted.
                                    @can('update', $server)
                                        <div class="mt-3">
                                            <x-forms.button type="button" wire:click="installSysbox"
                                                wire:loading.attr="disabled" wire:target="installSysbox">
                                                Install Sysbox
                                            </x-forms.button>
                                        </div>
                                    @endcan
                                </x-callout>
                            @else
                                <x-callout type="info" title="Checking Sysbox">
                                    Coolify checks if Sysbox is installed on this server.
                                </x-callout>
                            @endif
                        </div>
                    </x-application.settings-section>

                    <x-application.settings-section id="github-runners-timeouts-section" title="Timeouts"
                        helper="All values are in minutes.">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.input id="capacityWaitTimeout" type="number" min="1" max="1440"
                                label="Queue wait" required canGate="update" :canResource="$server"
                                helper="A job that waits longer for a free runner is dropped." />
                            <x-forms.input id="idleTimeout" type="number" min="1" max="1440" label="Idle runner"
                                required canGate="update" :canResource="$server"
                                helper="A runner that gets no job in this time is removed." />
                            <x-forms.input id="jobTimeout" type="number" min="1" max="7200" label="Job" required
                                canGate="update" :canResource="$server"
                                helper="A job that runs longer is stopped." />
                        </div>
                    </x-application.settings-section>
                </form>
                @can('update', $server)
                    <x-process-dialog @sysbox-install-started.window="processDialogOpen = true" closeWithX size="xl">
                        <x-slot:title>Install Sysbox</x-slot:title>
                        <x-slot:content>
                            <livewire:activity-monitor header="Logs" fullHeight />
                        </x-slot:content>
                    </x-process-dialog>
                @endcan
            @endif

            @if ($server->isBuildServer() && $this->config?->is_enabled)
                <x-application.settings-section id="github-runners-executions-section" title="Recent runners"
                    helper="Queued jobs, active runners, and their results. The list updates every 10 seconds." flush>
                    <livewire:server.github-runner-executions :server="$server" />
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>

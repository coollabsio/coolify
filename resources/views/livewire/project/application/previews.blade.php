<div class="flex flex-col gap-6">
    <livewire:project.application.preview.form :application="$application" />

    @if (count($application->additional_servers) > 0)
        <x-callout type="info" title="Preview deployment server">
            Preview deployments run on {{ $application->destination->server->name }}.
        </x-callout>
    @endif

    @if ($application->is_github_based())
        <x-application.settings-section id="preview-pull-requests-section" title="Pull requests"
            helper="Load open pull requests from GitHub, then configure or deploy a preview." flush>
            <x-slot:actions>
                @isset($rate_limit_remaining)
                    <span class="text-xs text-neutral-500 dark:text-fg-dim">
                        {{ $rate_limit_remaining }} requests remaining
                    </span>
                @endisset
                @can('update', $application)
                    <x-forms.button wire:click="load_prs">
                        Load pull requests
                    </x-forms.button>
                @endcan
            </x-slot:actions>

            <div wire:loading.remove wire:target="load_prs">
                @forelse ($pull_requests as $pull_request)
                    <div
                        class="flex flex-col gap-3 border-b border-neutral-200 px-4 py-3.5 last:border-b-0 sm:flex-row sm:items-center dark:border-white/[0.07]">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 font-mono text-xs font-semibold text-neutral-600 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                            #{{ data_get($pull_request, 'number') }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <h4 class="truncate text-sm font-semibold text-black dark:text-fg">
                                {{ data_get($pull_request, 'title') }}
                            </h4>
                            <a target="_blank"
                                class="mt-1 inline-flex items-center gap-1 text-xs text-neutral-500 hover:text-coollabs dark:text-fg-dim dark:hover:text-warning"
                                href="{{ data_get($pull_request, 'html_url') }}">
                                Open on GitHub
                                <x-external-link />
                            </a>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @can('update', $application)
                                <x-forms.button
                                    wire:click="add('{{ data_get($pull_request, 'number') }}', '{{ data_get($pull_request, 'html_url') }}')">
                                    Configure
                                </x-forms.button>
                            @endcan
                            @can('deploy', $application)
                                <x-forms.button
                                    wire:click="add_and_deploy('{{ data_get($pull_request, 'number') }}', '{{ data_get($pull_request, 'html_url') }}')">
                                    Deploy preview
                                </x-forms.button>
                            @endcan
                        </div>
                    </div>
                @empty
                    <x-empty size="sm" title="No pull requests loaded"
                        description="Load open pull requests from GitHub to configure a preview deployment.">
                        <x-slot:icon>
                            <x-reicon name="sources" class="size-8" />
                        </x-slot:icon>
                    </x-empty>
                @endforelse
            </div>
        </x-application.settings-section>
    @endif

    @if ($application->build_pack === 'dockerimage')
        <x-application.settings-section id="manual-preview-section" title="Manual preview"
            helper="Deploy a preview directly from a Docker image tag.">
            <form wire:submit.prevent="addDockerImagePreview"
                class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                <x-forms.input id="manualPullRequestId" label="Preview ID"
                    helper="Used for domains, logs, container names, and cleanup." />
                <x-forms.input id="manualDockerTag" label="Docker tag"
                    helper="For example, pr_1234." />
                @can('deploy', $application)
                    <x-forms.button type="submit">Deploy preview</x-forms.button>
                @endcan
            </form>
        </x-application.settings-section>
    @endif

    <x-application.settings-section id="preview-deployments-section" title="Preview deployments"
        helper="Manage domains, deployments, logs, and lifecycle actions for configured previews." flush>
        @forelse (data_get($application, 'previews') as $previewName => $preview)
            @php
                $previewStatus = str(data_get($preview, 'status'));
                $previewIsRunning = $previewStatus->startsWith('running');
                $previewIsRestarting = $previewStatus->startsWith('restarting');
                $previewIsStopped = $previewStatus->startsWith('exited');
            @endphp
            <section class="border-b border-neutral-200 p-4 last:border-b-0 dark:border-white/[0.07]"
                wire:key="preview-container-{{ $preview->pull_request_id }}">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 font-mono text-xs font-semibold text-neutral-600 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                            #{{ data_get($preview, 'pull_request_id') }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="text-sm font-semibold text-black dark:text-fg">
                                    Preview #{{ data_get($preview, 'pull_request_id') }}
                                </h4>
                                @if ($previewIsRunning)
                                    <x-status.running :status="data_get($preview, 'status')" />
                                @elseif ($previewIsRestarting)
                                    <x-status.restarting :status="data_get($preview, 'status')" />
                                @else
                                    <x-status.stopped :status="data_get($preview, 'status')" />
                                @endif
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                @if (!$previewIsStopped && filled(data_get($preview, 'fqdn')))
                                    <a target="_blank" class="text-neutral-500 hover:text-coollabs dark:text-fg-dim dark:hover:text-warning"
                                        href="{{ data_get($preview, 'fqdn') }}">Open preview</a>
                                @endif
                                @if (filled(data_get($preview, 'pull_request_html_url')))
                                    <a target="_blank" class="text-neutral-500 hover:text-coollabs dark:text-fg-dim dark:hover:text-warning"
                                        href="{{ data_get($preview, 'pull_request_html_url') }}">Open pull request</a>
                                @endif
                                @if (count($parameters) > 0)
                                    <a {{ wireNavigate() }} class="text-neutral-500 hover:text-coollabs dark:text-fg-dim dark:hover:text-warning"
                                        href="{{ route('project.application.deployment.index', [...$parameters, 'pull_request_id' => data_get($preview, 'pull_request_id')]) }}">
                                        Deployment logs
                                    </a>
                                    <a {{ wireNavigate() }} class="text-neutral-500 hover:text-coollabs dark:text-fg-dim dark:hover:text-warning"
                                        href="{{ route('project.application.logs', [...$parameters, 'pull_request_id' => data_get($preview, 'pull_request_id')]) }}">
                                        Application logs
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                        @can('deploy', $application)
                            <x-forms.button
                                wire:click="force_deploy_without_cache({{ data_get($preview, 'pull_request_id') }})">
                                Rebuild
                            </x-forms.button>
                            <x-forms.button
                                wire:click="deploy({{ data_get($preview, 'pull_request_id') }}, null, false, '{{ data_get($preview, 'docker_registry_image_tag') }}')">
                                {{ $previewIsStopped ? 'Deploy' : 'Redeploy' }}
                            </x-forms.button>
                        @endcan
                        @if (!$previewIsStopped)
                            @can('deploy', $application)
                                <x-modal-confirmation title="Stop preview deployment?" buttonTitle="Stop"
                                    submitAction="stop({{ data_get($preview, 'pull_request_id') }})"
                                    :actions="[
                                        'This preview deployment will be stopped.',
                                        'All non-persistent preview data will be removed.',
                                    ]"
                                    :confirmWithText="false" :confirmWithPassword="false"
                                    step2ButtonText="Stop preview deployment">
                                    <x-slot:customButton>
                                        <x-reicon name="stop" class="size-4 text-error" />
                                        Stop
                                    </x-slot:customButton>
                                </x-modal-confirmation>
                            @endcan
                        @endif
                        @can('delete', $application)
                            <x-modal-confirmation title="Delete preview deployment?" buttonTitle="Delete"
                                isErrorButton submitAction="delete({{ data_get($preview, 'pull_request_id') }})"
                                :actions="['All containers for this preview deployment will be stopped and permanently deleted.']"
                                confirmationText="{{ data_get($preview, 'fqdn') . '/' }}"
                                confirmationLabel="Enter the preview deployment name to confirm deletion"
                                shortConfirmationLabel="Preview deployment name" :confirmWithPassword="false" />
                        @endcan
                    </div>
                </div>

                <div class="mt-4 border-t border-neutral-200 pt-4 dark:border-white/[0.07]">
                    @if ($application->build_pack === 'dockercompose')
                        @if (collect(json_decode($preview->docker_compose_domains))->count() === 0)
                            <form wire:submit="save_preview('{{ $preview->id }}')"
                                class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                                <x-forms.input label="Domain" helper="One domain per preview."
                                    id="previewFqdns.{{ $previewName }}" canGate="update"
                                    :canResource="$application"
                                    wire:change="save_preview('{{ $preview->id }}')" />
                                @can('update', $application)
                                    <x-forms.button wire:click="generate_preview('{{ $preview->id }}')">
                                        Generate domain
                                    </x-forms.button>
                                @endcan
                            </form>
                        @else
                            <div class="flex flex-col gap-3">
                                @foreach (collect(json_decode($preview->docker_compose_domains)) as $serviceName => $service)
                                    <livewire:project.application.previews-compose
                                        wire:key="preview-{{ $preview->pull_request_id }}-{{ $serviceName }}"
                                        :service="$service" :serviceName="$serviceName" :preview="$preview" />
                                @endforeach
                            </div>
                        @endif
                    @else
                        <form wire:submit="save_preview('{{ $preview->id }}')"
                            class="grid gap-3 {{ $application->build_pack === 'dockerimage'
                                ? 'md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]'
                                : 'md:grid-cols-[minmax(0,1fr)_auto]' }} md:items-end">
                            <x-forms.input label="Domain" helper="One domain per preview."
                                id="previewFqdns.{{ $previewName }}" canGate="update"
                                :canResource="$application"
                                wire:change="save_preview('{{ $preview->id }}')" />
                            @if ($application->build_pack === 'dockerimage')
                                <x-forms.input label="Docker tag" helper="Image tag used by this preview."
                                    id="previewDockerTags.{{ $previewName }}" canGate="update"
                                    :canResource="$application"
                                    wire:change="save_preview('{{ $preview->id }}')" />
                            @endif
                            @can('update', $application)
                                <x-forms.button wire:click="generate_preview('{{ $preview->id }}')">
                                    Generate domain
                                </x-forms.button>
                            @endcan
                        </form>
                    @endif
                </div>
            </section>
        @empty
            <x-empty title="No preview deployments"
                description="Configure a pull request or manual preview to create an isolated deployment.">
                <x-slot:icon>
                    <x-reicon name="eye" class="size-8" />
                </x-slot:icon>
            </x-empty>
        @endforelse
    </x-application.settings-section>

    <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal"
        confirmAction="confirmDomainUsage">
        The preview deployment domain is already used by another resource and may cause routing conflicts.
        <x-slot:consequences>
            <ul class="mt-2 ml-4 list-disc">
                <li>The preview deployment may not be accessible.</li>
                <li>SSL certificates may not work correctly.</li>
                <li>Requests may be routed unpredictably.</li>
            </ul>
        </x-slot:consequences>
    </x-domain-conflict-modal>
</div>

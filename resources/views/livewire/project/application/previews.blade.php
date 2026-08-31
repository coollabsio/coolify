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

            <div>
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
                        description="Load open pull requests from GitHub to configure a preview deployment."
                        icon-name="sources" />
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
                                <x-status-summary :status="data_get($preview, 'status')" title="Preview status" />
                                <x-application.restart-limit-warning :application="$preview" />
                            </div>
                        </div>
                    </div>

                    <div id="preview-header-controls-{{ data_get($preview, 'pull_request_id') }}"
                        class="flex shrink-0 flex-wrap items-center gap-2 lg:justify-end">
                        <div class="relative" x-data="{ open: false }" @click.outside="open = false"
                            @keydown.escape.window="open = false">
                            <button type="button" class="button gap-1.5" title="Preview links" @click="open = !open"
                                :aria-expanded="open" aria-haspopup="menu">
                                <x-reicon name="external-link" class="size-3.5 opacity-70" />
                                Links
                                <x-reicon name="chevron-down" class="size-3 opacity-55" />
                            </button>
                            <div x-cloak x-show="open" x-transition.origin.top.right
                                class="listbox-panel top-full! right-0! left-auto! z-[90]! mt-1! w-56! min-w-56!"
                                role="menu">
                                @if (!$previewIsStopped && filled(data_get($preview, 'fqdn')))
                                    <a target="_blank" title="Open preview in a new tab"
                                        class="listbox-option justify-start! gap-2.5!"
                                        href="{{ data_get($preview, 'fqdn') }}" @click="open = false" role="menuitem">
                                        <x-reicon name="external-link" class="size-3.5 opacity-70" />
                                        <span class="min-w-0 truncate">Open preview</span>
                                    </a>
                                @endif
                                @if (filled(data_get($preview, 'pull_request_html_url')))
                                    <a target="_blank" title="Open pull request in a new tab"
                                        class="listbox-option justify-start! gap-2.5!"
                                        href="{{ data_get($preview, 'pull_request_html_url') }}" @click="open = false"
                                        role="menuitem">
                                        <x-reicon name="external-link" class="size-3.5 opacity-70" />
                                        <span class="min-w-0 truncate">Open pull request</span>
                                    </a>
                                @endif
                            </div>
                        </div>

                        @if (count($parameters) > 0)
                            <div class="relative" x-data="{ open: false }" @click.outside="open = false"
                                @keydown.escape.window="open = false">
                                <button type="button" class="button gap-1.5" title="Preview logs" @click="open = !open"
                                    :aria-expanded="open" aria-haspopup="menu">
                                    <x-reicon name="browser-terminal" class="size-3.5 opacity-70" />
                                    Logs
                                    <x-reicon name="chevron-down" class="size-3 opacity-55" />
                                </button>
                                <div x-cloak x-show="open" x-transition.origin.top.right
                                    class="listbox-panel top-full! right-0! left-auto! z-[90]! mt-1! w-44! min-w-44!"
                                    role="menu">
                                    <a {{ wireNavigate() }} class="listbox-option justify-start! gap-2.5!"
                                        href="{{ route('project.application.deployment.index', [...$parameters, 'pull_request_id' => data_get($preview, 'pull_request_id')]) }}"
                                        @click="open = false" role="menuitem">
                                        <x-reicon name="graph" class="size-3.5 opacity-70" />
                                        Deployment logs
                                    </a>
                                    <a {{ wireNavigate() }} class="listbox-option justify-start! gap-2.5!"
                                        href="{{ route('project.application.logs', [...$parameters, 'pull_request_id' => data_get($preview, 'pull_request_id')]) }}"
                                        @click="open = false" role="menuitem">
                                        <x-reicon name="browser-terminal" class="size-3.5 opacity-70" />
                                        Runtime logs
                                    </a>
                                </div>
                            </div>
                        @endif

                        <div class="relative" x-data="{ open: false }" @click.outside="open = false"
                        @keydown.escape.window="open = false">
                        <button type="button" class="button gap-1.5" title="Preview actions" @click="open = !open"
                            :aria-expanded="open" aria-haspopup="menu">
                            Actions
                            <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                                <x-reicon name="chevron-down" class="size-3 opacity-55" />
                            </span>
                        </button>
                        <div x-cloak x-show="open" x-transition.origin.top.right
                            class="listbox-panel top-full! right-0! left-auto! z-[90]! mt-1! w-52! min-w-52!"
                            role="menu">
                            @can('deploy', $application)
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    wire:click="force_deploy_without_cache({{ data_get($preview, 'pull_request_id') }})"
                                    @click="open = false" role="menuitem">
                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                    Rebuild
                                </button>
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    wire:click="deploy({{ data_get($preview, 'pull_request_id') }}, null, false, '{{ data_get($preview, 'docker_registry_image_tag') }}')"
                                    @click="open = false" role="menuitem">
                                    <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                    {{ $previewIsStopped ? 'Deploy' : 'Redeploy' }}
                                </button>
                                @if (!$previewIsStopped)
                                    <button type="button"
                                        class="listbox-option justify-start! gap-2.5! text-error!"
                                        @click="open = false; document.getElementById('preview-stop-trigger-{{ data_get($preview, 'pull_request_id') }}')?.click()"
                                        role="menuitem">
                                        <x-reicon name="stop" class="size-3.5" />
                                        Stop
                                    </button>
                                @endif
                            @endcan
                            @can('delete', $application)
                                <button type="button"
                                    class="listbox-option justify-start! gap-2.5! text-error!"
                                    @click="open = false; document.getElementById('preview-delete-trigger-{{ data_get($preview, 'pull_request_id') }}')?.click()"
                                    role="menuitem">
                                    <x-reicon name="trash" class="size-3.5" />
                                    Delete
                                </button>
                            @endcan
                        </div>
                    </div>
                    </div>

                    <div class="hidden" aria-hidden="true">
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
                                    <x-slot:trigger>
                                        <button id="preview-stop-trigger-{{ data_get($preview, 'pull_request_id') }}"
                                            type="button"></button>
                                    </x-slot:trigger>
                                </x-modal-confirmation>
                            @endcan
                        @endif
                        @can('delete', $application)
                            <x-modal-confirmation title="Delete preview deployment?" buttonTitle="Delete"
                                isErrorButton submitAction="delete({{ data_get($preview, 'pull_request_id') }})"
                                :actions="['All containers for this preview deployment will be stopped and permanently deleted.']"
                                confirmationText="{{ data_get($preview, 'fqdn') . '/' }}"
                                confirmationLabel="Enter the preview deployment name to confirm deletion"
                                shortConfirmationLabel="Preview deployment name" :confirmWithPassword="false">
                                <x-slot:trigger>
                                    <button id="preview-delete-trigger-{{ data_get($preview, 'pull_request_id') }}"
                                        type="button"></button>
                                </x-slot:trigger>
                            </x-modal-confirmation>
                        @endcan
                    </div>
                </div>

                <div class="mt-4 border-t border-neutral-200 pt-4 dark:border-white/[0.07]">
                    <livewire:project.application.preview-domains
                        wire:key="preview-domains-{{ $preview->id }}"
                        :preview="$preview" />

                    @if ($application->build_pack === 'dockerimage')
                        <form wire:submit="save_preview('{{ $preview->id }}')"
                            class="application-settings-section-body is-flush mt-3 overflow-visible">
                            <div class="data-table-header grid-cols-1"><span>Docker tag</span></div>
                            <div class="p-3">
                                <x-forms.input id="previewDockerTags.{{ $previewName }}" canGate="update"
                                    :canResource="$application"
                                    wire:change="save_preview('{{ $preview->id }}')" />
                            </div>
                        </form>
                    @endif
                </div>
            </section>
        @empty
            <x-empty title="No preview deployments"
                description="Configure a pull request or manual preview to create an isolated deployment."
                icon-name="eye" />
        @endforelse
    </x-application.settings-section>

</div>

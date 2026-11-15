@php
    $canUpdate = auth()->user()->can('update', $workload);
@endphp
<div class="flex flex-col gap-6" wire:poll.10000ms>
    <x-application.settings-section id="preview-settings-section" title="Preview settings"
        helper="Automatic pull request deployments, who can trigger them, and preview variables.">
        @if ($canUpdate)
            <x-slot:actions>
                @if ($workload->github_app_id !== null)
                    <x-modal-input title="Pull requests"
                        subtitle="Load open pull requests from GitHub, then deploy a preview."
                        :wireIgnore="false" :isLarge="true">
                        <x-slot:content>
                            <x-forms.button wire:click="loadPullRequests">
                                Load pull requests
                            </x-forms.button>
                        </x-slot:content>
                        <x-slot:headerActions>
                            @isset($rateLimitRemaining)
                                <span class="text-xs text-neutral-500 dark:text-fg-dim">
                                    {{ $rateLimitRemaining }} requests remaining
                                </span>
                            @endisset
                            <x-forms.button wire:click="loadPullRequests">
                                Refresh
                            </x-forms.button>
                        </x-slot:headerActions>

                        <div class="-m-4" wire:loading wire:target="loadPullRequests">
                            <x-skeleton.list :rows="3" label="Loading pull requests" />
                        </div>

                        <div class="-m-4" wire:loading.remove wire:target="loadPullRequests">
                            @forelse ($pullRequests as $pullRequest)
                                <div wire:key="pull-request-{{ $pullRequest['number'] }}"
                                    class="flex flex-col gap-3 border-b border-neutral-200 px-4 py-3.5 last:border-b-0 sm:flex-row sm:items-center dark:border-white/[0.07]">
                                    <div
                                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 font-mono text-xs font-semibold text-neutral-600 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                                        #{{ $pullRequest['number'] }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h4 class="truncate text-sm font-semibold text-black dark:text-fg">
                                            {{ $pullRequest['title'] }}
                                        </h4>
                                        <div class="mt-1 flex items-center gap-2 text-xs text-neutral-500 dark:text-fg-dim">
                                            @if ($pullRequest['from_fork'])
                                                <span class="font-semibold text-warning">From a fork</span>
                                            @endif
                                            <a target="_blank" rel="noopener noreferrer"
                                                class="inline-flex items-center gap-1 hover:text-coollabs dark:hover:text-brand-light"
                                                href="{{ $pullRequest['html_url'] }}">
                                                Open on GitHub
                                                <x-external-link />
                                            </a>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <x-forms.button wire:click="deployLoadedPullRequest({{ $pullRequest['number'] }})">
                                            Deploy preview
                                        </x-forms.button>
                                    </div>
                                </div>
                            @empty
                                <x-empty size="sm" title="No open pull requests"
                                    description="No open pull requests were found for this repository."
                                    icon-name="sources" />
                            @endforelse
                        </div>
                    </x-modal-input>
                @endif
                @if ($isPreviewDeploymentsEnabled)
                    <x-forms.button wire:click="togglePreviewDeployments" wire:target="togglePreviewDeployments">
                        Disable preview deployments
                    </x-forms.button>
                @else
                    <x-forms.button wire:click="togglePreviewDeployments" wire:target="togglePreviewDeployments"
                        isHighlighted>
                        Enable preview deployments
                    </x-forms.button>
                @endif
            </x-slot:actions>
        @endif

        <div class="grid w-full gap-4 md:grid-cols-2">
            <x-forms.listbox id="isPrDeploymentsPublicEnabled" label="PR deployment access" onChange="savePreviewSettings"
                helper="When public, anyone can trigger PR deployments. Otherwise only PRs from branches in the same repository are deployed and fork PRs are blocked.<br><br><span class='font-bold'>Secrets:</span> the code of a fork PR can read all environment variables of its preview. A preview of a fork PR gets only the preview variables, no production values, and no outbound firewall rules of this application. Its build shares the BuildKit cache of the build server with production builds."
                :options="[
                    ['value' => false, 'label' => 'Repository members only'],
                    ['value' => true, 'label' => 'Public (fork PRs allowed)'],
                ]" :disabled="! $canUpdate || ! $isPreviewDeploymentsEnabled" />
            <x-forms.listbox id="previewUsesProductionValues" label="Production values" onChange="savePreviewSettings"
                helper="<span class='font-bold'>Use production values:</span> a preview of a PR from the same repository gets the production value of each variable that has no preview variable.<br><span class='font-bold'>Preview variables only:</span> previews get only the preview variables. Use this when untrusted code can run in previews, or when production secrets must not reach previews.<br><br>Set the preview variables on the Environment Variables tab. They apply at the next deploy of a preview."
                :options="[
                    ['value' => true, 'label' => 'Use production values'],
                    ['value' => false, 'label' => 'Preview variables only'],
                ]" :disabled="! $canUpdate" />
        </div>
    </x-application.settings-section>

    <form wire:submit="saveUrlTemplate" class="application-settings-form flex flex-col">
        <x-application.settings-section id="preview-template-section" title="Preview URL template"
            helper="Define how Coolify generates domains for pull request deployments.">
            @if ($canUpdate)
                <x-slot:actions>
                    <x-forms.button type="button" wire:click="resetUrlTemplate">
                        Reset template
                    </x-forms.button>
                    <x-forms.button type="submit">Save</x-forms.button>
                </x-slot:actions>
            @endif

            <x-forms.input id="previewUrlTemplate" label="URL template"
                helper="Use @@{{ random }} for a random subdomain, @@{{ pr_id }} for the pull request number, or @@{{ domain }} for the application domain. Without a domain, @@{{ domain }} is a generated domain. A new template applies to new previews."
                :disabled="! $canUpdate" />
        </x-application.settings-section>
    </form>

    @if ($canUpdate)
        <x-application.settings-section id="manual-preview-section" title="Manual preview"
            helper="Deploy a preview of a pull request by its number. Coolify builds the latest commit of the pull request.">
            <form wire:submit="deployPullRequest" class="flex flex-col gap-4">
                <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    <x-forms.input id="pullRequestNumber" label="Pull request number" type="number" min="1" placeholder="12" required />
                    <x-forms.button type="submit">Deploy preview</x-forms.button>
                </div>
                <x-callout type="warning" title="You decide to trust this code">
                    A manual deploy treats the pull request like one from a branch of this repository, also when it is from a fork. The preview gets the production values when Use production values is on, and the outbound firewall rules of this application. Check the code of the pull request first.
                </x-callout>
                @if ($domainError)
                    <x-callout type="warning" title="The preview has no domain">{{ $domainError }}</x-callout>
                @endif
            </form>
        </x-application.settings-section>
    @endif

    <x-application.settings-section id="preview-deployments-section" title="Preview deployments"
        helper="Manage domains, deployments, logs, and lifecycle actions for configured previews. A preview runs on the first server of this application, with new empty volumes." flush>
        @forelse ($previews as $preview)
            @php
                $previewParameters = [...$routeParameters, 'workload_uuid' => $preview['uuid']];
                $previewIsStopped = $preview['state'] !== \App\Enums\NodeWorkloadState::RUNNING;
            @endphp
            <section class="border-b border-neutral-200 p-4 last:border-b-0 dark:border-white/[0.07]"
                wire:key="cluster-preview-{{ $preview['uuid'] }}">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 font-mono text-xs font-semibold text-neutral-600 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                            #{{ $preview['number'] }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <a class="text-sm font-semibold text-black hover:underline dark:text-fg" {{ wireNavigate() }}
                                    href="{{ route('project.cluster-application.show', $previewParameters) }}">
                                    Preview #{{ $preview['number'] }}
                                </a>
                                @if ($preview['removing'])
                                    <x-status-badge status="Deleting" type="warning" pulse />
                                @else
                                    <x-status-badge :status="$preview['status']" :type="$preview['statusType']" />
                                @endif
                                @if ($preview['fromFork'])
                                    <span class="text-[11px] text-neutral-500 dark:text-fg-dim">fork</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    @unless ($preview['removing'])
                        <div class="flex shrink-0 flex-wrap items-center gap-2 lg:justify-end">
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
                                    @if (! $previewIsStopped && filled($preview['firstDomain']))
                                        <a target="_blank" rel="noopener noreferrer" title="Open preview in a new tab"
                                            class="listbox-option justify-start! gap-2.5!"
                                            href="{{ $preview['firstDomain'] }}" @click="open = false" role="menuitem">
                                            <x-reicon name="external-link" class="size-3.5 opacity-70" />
                                            <span class="min-w-0 truncate">Open preview</span>
                                        </a>
                                    @endif
                                    @if (filled($preview['url']))
                                        <a target="_blank" rel="noopener noreferrer" title="Open pull request in a new tab"
                                            class="listbox-option justify-start! gap-2.5!"
                                            href="{{ $preview['url'] }}" @click="open = false" role="menuitem">
                                            <x-reicon name="external-link" class="size-3.5 opacity-70" />
                                            <span class="min-w-0 truncate">Open pull request</span>
                                        </a>
                                    @endif
                                    @if ($previewIsStopped && blank($preview['url']))
                                        <span class="listbox-option pointer-events-none justify-start! text-neutral-500! dark:text-fg-dim!">No links</span>
                                    @endif
                                </div>
                            </div>

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
                                        href="{{ $preview['latestBuildUuid'] ? route('project.cluster-application.build.show', [...$previewParameters, 'build_uuid' => $preview['latestBuildUuid']]) : route('project.cluster-application.deployments', $previewParameters) }}"
                                        @click="open = false" role="menuitem">
                                        <x-reicon name="graph" class="size-3.5 opacity-70" />
                                        Deployment logs
                                    </a>
                                    <a {{ wireNavigate() }} class="listbox-option justify-start! gap-2.5!"
                                        href="{{ route('project.cluster-application.logs', $previewParameters) }}"
                                        @click="open = false" role="menuitem">
                                        <x-reicon name="browser-terminal" class="size-3.5 opacity-70" />
                                        Runtime logs
                                    </a>
                                </div>
                            </div>

                            @if ($canUpdate)
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
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            wire:click="redeploy('{{ $preview['uuid'] }}')" @click="open = false" role="menuitem">
                                            <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                            {{ $previewIsStopped && ! $preview['containerPresent'] ? 'Deploy' : 'Redeploy' }}
                                        </button>
                                        @if ($previewIsStopped && $preview['containerPresent'])
                                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                                wire:click="start('{{ $preview['uuid'] }}')" @click="open = false" role="menuitem">
                                                <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                                Start
                                            </button>
                                        @endif
                                        <div class="my-1 border-t border-neutral-200 dark:border-white/[0.08]"></div>
                                        @unless ($previewIsStopped)
                                            <button type="button"
                                                class="listbox-option justify-start! gap-2.5! text-error! hover:text-error!"
                                                @click="open = false; document.getElementById('preview-stop-trigger-{{ $preview['uuid'] }}')?.click()"
                                                role="menuitem">
                                                <x-reicon name="stop" class="size-3.5" />
                                                Stop
                                            </button>
                                        @endunless
                                        <button type="button"
                                            class="listbox-option justify-start! gap-2.5! text-error! hover:text-error!"
                                            @click="open = false; document.getElementById('preview-delete-trigger-{{ $preview['uuid'] }}')?.click()"
                                            role="menuitem">
                                            <x-reicon name="trash" class="size-3.5" />
                                            Delete
                                        </button>
                                    </div>
                                </div>

                                {{-- Keyed by state: the wire:ignore confirmations must render again when the preview stops or starts. --}}
                                <div class="hidden" aria-hidden="true" wire:key="cluster-preview-confirmations-{{ $preview['uuid'] }}-{{ $previewIsStopped ? 'stopped' : 'running' }}">
                                    @unless ($previewIsStopped)
                                        <x-modal-confirmation title="Stop preview deployment?" buttonTitle="Stop"
                                            submitAction="stop('{{ $preview['uuid'] }}')"
                                            :actions="[
                                                'This preview deployment will be stopped.',
                                                'Its container is stopped. Its volumes are kept.',
                                            ]"
                                            :confirmWithText="false" :confirmWithPassword="false"
                                            step2ButtonText="Stop preview deployment">
                                            <x-slot:trigger>
                                                <button id="preview-stop-trigger-{{ $preview['uuid'] }}" type="button"></button>
                                            </x-slot:trigger>
                                        </x-modal-confirmation>
                                    @endunless
                                    <x-modal-confirmation title="Delete preview deployment?" buttonTitle="Delete"
                                        isErrorButton submitAction="delete('{{ $preview['uuid'] }}')"
                                        :actions="['All containers, volumes, and domains of this preview deployment will be stopped and permanently deleted.']"
                                        confirmationText="{{ $workload->name }}-pr-{{ $preview['number'] }}"
                                        confirmationLabel="Enter the preview deployment name to confirm deletion"
                                        shortConfirmationLabel="Preview deployment name" :confirmWithPassword="false">
                                        <x-slot:trigger>
                                            <button id="preview-delete-trigger-{{ $preview['uuid'] }}" type="button"></button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                </div>
                            @endif
                        </div>
                    @endunless
                </div>

                <form wire:submit="saveDomains('{{ $preview['uuid'] }}')"
                    class="mt-4 flex flex-col gap-2 border-t border-neutral-200 pt-4 sm:flex-row sm:items-end dark:border-white/[0.07]">
                    <div class="min-w-0 flex-1">
                        <x-forms.input id="previewDomains.{{ $preview['uuid'] }}" label="Domains"
                            helper="Separate domains with a comma. A domain without a port keeps the container port of the domain it replaces. The next deploy of the pull request keeps these domains."
                            :disabled="! $canUpdate || $preview['removing']" />
                    </div>
                    @if ($canUpdate && ! $preview['removing'])
                        <x-forms.button type="submit">Save</x-forms.button>
                    @endif
                </form>
            </section>
        @empty
            <x-empty title="No preview deployments"
                description="{{ $isPreviewDeploymentsEnabled ? 'Open a pull request, or deploy one manually.' : 'Enable preview deployments, or deploy a pull request manually.' }}"
                icon-name="eye" />
        @endforelse
    </x-application.settings-section>
</div>

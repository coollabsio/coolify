@php
    $canUpdate = auth()->user()->can('update', $workload);
    $gridClass = 'grid-cols-[6rem_7rem_minmax(0,1fr)_minmax(0,12rem)_6rem]';
    $templateHelper = 'Applied to each domain of this application. {{domain}} is the domain, {{pr_id}} is the pull request number, and {{random}} is a random ID. Without a domain, {{domain}} is a generated domain. A new template applies to new previews.';
@endphp
<div class="flex flex-col gap-6" wire:poll.10000ms>
    <x-application.settings-section id="cluster-application-preview-settings" title="Preview settings"
        helper="Each pull request to the branch of this application gets its own preview application with a generated domain. Coolify removes the preview when the pull request closes.">
        <div class="grid w-full gap-4 md:grid-cols-2">
            <x-forms.listbox id="previewDeploymentsEnabled" label="Preview deployments" onChange="saveSettings"
                helper="Pull request webhooks deploy previews only when this is on. A closed pull request always removes its preview."
                :options="[
                    ['value' => false, 'label' => 'Off'],
                    ['value' => true, 'label' => 'On'],
                ]" :disabled="! $canUpdate" />
            <x-forms.listbox id="forkPreviewDeploymentsEnabled" label="Pull requests from forks" onChange="saveSettings"
                helper="Off: only pull requests from branches of the same repository deploy. People who can push there have write access.<br><br><span class='font-bold'>On:</span> anyone who opens a pull request can run code in a preview. A preview of a fork gets only the preview variables, no production values, and no outbound firewall rules of this application. Its build shares the BuildKit cache of the build server with production builds."
                :options="[
                    ['value' => false, 'label' => 'Repository members only'],
                    ['value' => true, 'label' => 'Public (fork pull requests allowed)'],
                ]" :disabled="! $canUpdate" />
            <x-forms.listbox id="previewUsesProductionValues" label="Production values" onChange="saveSettings"
                helper="On: a preview of a pull request that is not from a fork gets the production value of each variable that has no preview variable.<br><br>Off: previews get only the preview variables."
                :options="[
                    ['value' => true, 'label' => 'Use production values'],
                    ['value' => false, 'label' => 'Preview variables only'],
                ]" :disabled="! $canUpdate" />
            <form wire:submit="saveSettings" class="flex items-end gap-2">
                <x-forms.input id="previewUrlTemplate" label="Preview URL template" :disabled="! $canUpdate"
                    :helper="$templateHelper" />
                @if ($canUpdate)
                    <x-forms.button type="submit">Save</x-forms.button>
                @endif
            </form>
        </div>
    </x-application.settings-section>

    <x-application.settings-section id="cluster-application-previews" title="Previews" :flush="$previews !== []"
        helper="The previews of the pull requests of this application. A preview runs on the first server of this application, with new empty volumes.">
        @if ($canUpdate)
            <x-slot:actions>
                <x-modal-input title="Deploy pull request" :closeOutside="false" :wireIgnore="false">
                    <x-slot:content>
                        <button type="button" class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            Deploy pull request
                        </button>
                    </x-slot:content>
                    <form wire:submit="deployPullRequest" class="application-settings-form flex flex-col gap-4">
                        <x-forms.input id="pullRequestNumber" label="Pull request number" type="number" min="1" placeholder="12" required
                            helper="Coolify builds the latest commit of the pull request." />
                        <x-callout type="warning" title="You decide to trust this code">
                            A manual deploy treats the pull request like one from a branch of this repository, also when it is from a fork. The preview gets the production values when Use production values is on, and the outbound firewall rules of this application. Check the code of the pull request first.
                        </x-callout>
                        <div class="flex justify-end pt-2">
                            <x-forms.button type="submit" isHighlighted @click="modalOpen = false">Deploy</x-forms.button>
                        </div>
                    </form>
                </x-modal-input>
            </x-slot:actions>
        @endif

        @if ($domainError)
            <div @class(['px-4 pt-4' => $previews !== []])>
                <x-callout type="warning" title="The preview has no domain">{{ $domainError }}</x-callout>
            </div>
        @endif

        @if ($previews === [])
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                No previews. {{ $workload->preview_deployments_enabled ? 'Open a pull request, or deploy one here.' : 'Turn on preview deployments, or deploy a pull request here.' }}
            </p>
        @else
            <div class="data-table-header {{ $gridClass }}">
                <span>Pull request</span>
                <span>Status</span>
                <span>Domain</span>
                <span>Latest build</span>
                <span class="text-right">Actions</span>
            </div>
            <div class="data-table w-full">
                @foreach ($previews as $preview)
                    <div wire:key="cluster-application-preview-{{ $preview['uuid'] }}" class="env-table-item">
                        <div class="data-table-row {{ $gridClass }} py-2!">
                            <div class="flex min-w-0 flex-col">
                                <a class="text-[13px] font-medium text-black hover:underline dark:text-fg" {{ wireNavigate() }}
                                    href="{{ route('project.cluster-application.show', [...$routeParameters, 'workload_uuid' => $preview['uuid']]) }}">#{{ $preview['number'] }}</a>
                                <span class="flex items-center gap-1 text-[11px] text-neutral-500 dark:text-fg-dim">
                                    @if ($preview['url'])
                                        <a href="{{ $preview['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-0.5 hover:underline">Open<x-external-link /></a>
                                    @endif
                                    @if ($preview['fromFork'])
                                        <span>fork</span>
                                    @endif
                                </span>
                            </div>
                            <div class="justify-self-start">
                                @if ($preview['removing'])
                                    <x-status-badge status="Removing" type="warning" pulse />
                                @else
                                    <x-status-badge :status="$preview['status']" :type="$preview['statusType']" />
                                @endif
                            </div>
                            <div class="flex min-w-0 flex-col">
                                @forelse ($preview['domains'] as $domain)
                                    <a href="{{ $domain }}" target="_blank" rel="noopener noreferrer" class="truncate font-mono text-[12px] hover:underline" title="{{ $domain }}">{{ $domain }}</a>
                                @empty
                                    <span class="text-[12px] text-neutral-500 dark:text-fg-dim">No domain</span>
                                @endforelse
                            </div>
                            <div class="min-w-0 text-[12px]">
                                @if ($preview['build'])
                                    <a class="hover:underline" {{ wireNavigate() }}
                                        href="{{ route('project.cluster-application.build.show', [...$routeParameters, 'workload_uuid' => $preview['uuid'], 'build_uuid' => $preview['build']['uuid']]) }}">{{ $preview['build']['status'] }}</a>
                                    <span class="block truncate text-[11px] text-neutral-500 dark:text-fg-dim">
                                        @if ($preview['build']['commit'])<span class="font-mono">{{ $preview['build']['commit'] }}</span> · @endif{{ $preview['build']['at'] }}
                                    </span>
                                @else
                                    <span class="text-neutral-500 dark:text-fg-dim">-</span>
                                @endif
                            </div>
                            <div class="flex items-center justify-end gap-1">
                                @if ($canUpdate && ! $preview['removing'])
                                    <x-modal-confirmation class="!w-auto shrink-0" title="Delete preview?" buttonTitle="Delete"
                                        isErrorButton canGate="update" :canResource="$workload"
                                        submitAction="removePreview('{{ $preview['uuid'] }}')" :actions="[
                                            'The preview of pull request #'.$preview['number'].' is deleted with its containers, volumes, and domains.',
                                            'The next commit of an open pull request creates it again while previews are on.',
                                        ]" :confirmWithPassword="false" :confirmWithText="false" step2ButtonText="Delete">
                                        <x-slot:trigger>
                                            <button type="button" class="button text-red-500! dark:text-red-400!">Delete</button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>

    <livewire:project.shared.environment-variable.all :resource="$workload" :previewScope="true"
        wire:key="cluster-application-preview-env-{{ $workload->uuid }}" />
</div>

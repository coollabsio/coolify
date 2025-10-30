<div>
    <div class="flex items-center gap-2">
        <h2>Domains</h2>
        @can('update', $application)
            <x-modal-input buttonTitle="+ Add" title="New Domain">
                <livewire:project.application.domain-add :application="$application" />
            </x-modal-input>
            <x-forms.button wire:click="generateDomain">Generate Domain</x-forms.button>
        @endcan
    </div>
    <div class="pb-4">Manage domains for your application.</div>

    @if ($application->build_pack === 'dockercompose')
        <x-callout type="warning" title="Note" class="mb-4">
            This view is for simple applications only. Docker Compose applications manage domains per service in the General view.
        </x-callout>
    @endif

    @if (empty($domains))
        <div class="flex flex-col items-center justify-center py-8 text-center">
            <svg class="w-16 h-16 mb-4 text-neutral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
            </svg>
            <h3 class="mb-2">No domains configured</h3>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                Add a domain to make your application accessible via a custom URL.
            </p>
        </div>
    @else
        <div class="flex flex-col gap-2 pb-6">
            @foreach ($domains as $index => $domain)
                <div class="flex items-center gap-4 p-2 min-h-[4rem] rounded-sm border border-neutral-200 dark:border-coolgray-300 bg-white dark:bg-coolgray-100 shadow-sm">
                    {{-- Check Icon --}}
                    <div class="flex-shrink-0">
                        <svg class="w-6 h-6 text-success" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd"
                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                clip-rule="evenodd" />
                        </svg>
                    </div>

                    {{-- Domain Name --}}
                    <div class="flex-1 min-w-0">
                        <div class="font-mono text-base break-all text-black dark:text-white">{{ $domain }}</div>
                        <div class="text-xs text-neutral-500 dark:text-neutral-400">Valid Configuration</div>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="flex gap-2">
                        @can('view', $application)
                            <x-forms.button wire:click="checkDNS('{{ $domain }}')" title="Check DNS">
                                Refresh
                            </x-forms.button>
                        @endcan
                        @can('update', $application)
                            <x-modal-input buttonTitle="Edit" title="Edit Domain">
                                <livewire:project.application.domain-edit :application="$application" :index="$index"
                                    :domain="$domain" :key="'edit-' . $index" />
                            </x-modal-input>
                            <x-modal-confirmation title="Remove Domain?"
                                message="Are you sure you want to remove this domain: {{ $domain }}?"
                                buttonTitle="Remove" submitAction="removeDomain({{ $index }})"
                                :confirmWithText="false" :confirmWithPassword="false" isErrorButton>
                                <x-slot:button>
                                    <x-forms.button isError title="Remove domain">
                                        Remove
                                    </x-forms.button>
                                </x-slot:button>
                            </x-modal-confirmation>
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>

        <div class="pt-4">
            <h3>Redirect Settings</h3>
            <div class="pb-2 text-sm">Configure how www and non-www domains should be handled.</div>

            @if ($application->settings->is_container_label_readonly_enabled == false)
                @if ($application->redirect === 'both')
                    <x-forms.input label="Direction" value="Allow www & non-www." readonly
                        helper="Readonly labels are disabled. You can set the direction in the labels section." />
                @elseif ($application->redirect === 'www')
                    <x-forms.input label="Direction" value="Redirect to www." readonly
                        helper="Readonly labels are disabled. You can set the direction in the labels section." />
                @elseif ($application->redirect === 'non-www')
                    <x-forms.input label="Direction" value="Redirect to non-www." readonly
                        helper="Readonly labels are disabled. You can set the direction in the labels section." />
                @endif
            @else
                <div class="flex items-end gap-2">
                    <x-forms.select wire:model="redirect" label="Direction" required
                        helper="You must need to add www and non-www as an A DNS record. Make sure the www domain is added under your domains list.">
                        <option value="both">Allow www & non-www.</option>
                        <option value="www">Redirect to www.</option>
                        <option value="non-www">Redirect to non-www.</option>
                    </x-forms.select>
                    @can('update', $application)
                        <x-modal-confirmation title="Confirm Redirection Setting?" buttonTitle="Set Direction"
                            submitAction="setRedirect" :actions="['All traffic will be redirected to the selected direction.']"
                            confirmationText="{{ $application->fqdn . '/' }}"
                            confirmationLabel="Please confirm the execution of the action by entering the Application URL below"
                            shortConfirmationLabel="Application URL" :confirmWithPassword="false"
                            step2ButtonText="Set Direction">
                            <x-slot:customButton>
                                <div class="w-[7.2rem]">Set Direction</div>
                            </x-slot:customButton>
                        </x-modal-confirmation>
                    @endcan
                </div>
            @endif
        </div>
    @endif

    {{-- Domain Conflict Modal --}}
    <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal"
        confirmAction="confirmDomainUsage" />
</div>

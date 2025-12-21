<div>
    <form wire:submit='submit'>
        <div class="flex items-center gap-2 pb-4">
            @if ($application->human_name)
                <h2>{{ Str::headline($application->human_name) }}</h2>
            @else
                <h2>{{ Str::headline($application->name) }}</h2>
            @endif
            <x-forms.button canGate="update" :canResource="$application" type="submit">{{ __('button.save') }}</x-forms.button>
            @can('update', $application)
                <x-modal-confirmation wire:click="convertToDatabase" title="{{ __('service.convert_to_database') }}"
                    buttonTitle="{{ __('service.convert_to_database') }}" submitAction="convertToDatabase" :actions="[__('service.convert_to_database_warning')]"
                    confirmationText="{{ Str::headline($application->name) }}"
                    confirmationLabel="{{ __('service.confirm_service_application_deletion_label') }}"
                    shortConfirmationLabel="{{ __('service.service_application_name') }}" />
            @endcan
            @can('delete', $application)
                <x-modal-confirmation title="{{ __('modal.confirm_service_application_deletion') }}" buttonTitle="{{ __('modal.delete_service_application') }}" isErrorButton
                    submitAction="delete" :actions="[__('service.service_application_deletion_warning')]" confirmationText="{{ Str::headline($application->name) }}"
                    confirmationLabel="{{ __('service.confirm_service_application_deletion_label') }}"
                    shortConfirmationLabel="{{ __('service.service_application_name') }}" />
            @endcan
        </div>
        <div class="flex flex-col gap-2">
            @if($requiredPort && !$application->serviceType()?->contains(str($application->image)->before(':')))
                <x-callout type="warning" title="{{ __('service.required_port', ['port' => $requiredPort]) }}" class="mb-2">
                    {!! __('service.required_port_helper', ['port' => $requiredPort]) !!}
                </x-callout>
            @endif

            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$application" label="{{ __('input.name') }}" id="humanName"
                    placeholder="{{ __('service.human_readable_name') }}"></x-forms.input>
                <x-forms.input canGate="update" :canResource="$application" label="{{ __('input.description') }}"
                    id="description"></x-forms.input>
            </div>
            <div class="flex gap-2">
                @if (!$application->serviceType()?->contains(str($application->image)->before(':')))
                    @if ($application->required_fqdn)
                        <x-forms.input canGate="update" :canResource="$application" required placeholder="https://app.coolify.io"
                            label="{{ __('application.domains') }}" id="fqdn"
                            helper="{{ __('application.domains_helper') }}"></x-forms.input>
                    @else
                        <x-forms.input canGate="update" :canResource="$application" placeholder="https://app.coolify.io"
                            label="{{ __('application.domains') }}" id="fqdn"
                            helper="{{ __('application.domains_helper') }}"></x-forms.input>
                    @endif
                @endif
                <x-forms.input canGate="update" :canResource="$application"
                    helper="{!! __('service.image_helper') !!}"
                    label="{{ __('input.image') }}" id="image"></x-forms.input>
            </div>
        </div>
        <h3 class="py-2 pt-4">{{ __('menu.advanced') }}</h3>
        <div class="w-96 flex flex-col gap-1">
            @if (str($application->image)->contains('pocketbase'))
                <x-forms.checkbox canGate="update" :canResource="$application" instantSave="instantSaveSettings" id="isGzipEnabled"
                    label="{{ __('service.gzip_compression') }}"
                    helper="{{ __('service.pocketbase_gzip_helper') }}" disabled />
            @else
                <x-forms.checkbox canGate="update" :canResource="$application" instantSave="instantSaveSettings" id="isGzipEnabled"
                    label="{{ __('service.gzip_compression') }}"
                    helper="{{ __('service.gzip_helper') }}" />
            @endif
            <x-forms.checkbox canGate="update" :canResource="$application" instantSave="instantSaveSettings" id="isStripprefixEnabled"
                label="{{ __('service.strip_prefixes') }}"
                helper="{{ __('service.strip_prefixes_helper') }}" />
            <x-forms.checkbox canGate="update" :canResource="$application" instantSave="instantSaveSettings" label="{{ __('service.exclude_from_status') }}"
                helper="{{ __('service.exclude_from_status_helper') }}"
                id="excludeFromStatus"></x-forms.checkbox>
            <x-forms.checkbox canGate="update" :canResource="$application"
                helper="{{ __('service.drain_logs_helper') }}"
                instantSave="instantSaveAdvanced" id="isLogDrainEnabled" label="{{ __('service.drain_logs') }}" />
        </div>
    </form>
    
    <x-domain-conflict-modal
        :conflicts="$domainConflicts"
        :showModal="$showDomainConflictModal"
        confirmAction="confirmDomainUsage">
        <x-slot:consequences>
            <ul class="mt-2 ml-4 list-disc">
                <li>{{ __('service.domain_conflict_consequences.only_one') }}</li>
                <li>{{ __('service.domain_conflict_consequences.unpredictable') }}</li>
                <li>{{ __('service.domain_conflict_consequences.disruptions') }}</li>
                <li>{{ __('service.domain_conflict_consequences.ssl') }}</li>
            </ul>
        </x-slot:consequences>
    </x-domain-conflict-modal>

    @if ($showPortWarningModal)
        <div x-data="{ modalOpen: true }" x-init="$nextTick(() => { modalOpen = true })"
            @keydown.escape.window="modalOpen = false; $wire.call('cancelRemovePort')"
            :class="{ 'z-40': modalOpen }" class="relative w-auto h-auto">
            <template x-teleport="body">
                <div x-show="modalOpen"
                    class="fixed top-0 lg:pt-10 left-0 z-99 flex items-start justify-center w-screen h-screen" x-cloak>
                    <div x-show="modalOpen" class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="relative w-full py-6 border rounded-sm min-w-full lg:min-w-[36rem] max-w-[48rem] bg-neutral-100 border-neutral-400 dark:bg-base px-7 dark:border-coolgray-300">
                        <div class="flex justify-between items-center pb-3">
                            <h2 class="pr-8 font-bold">{{ __('service.remove_required_port') }}</h2>
                            <button @click="modalOpen = false; $wire.call('cancelRemovePort')"
                                class="flex absolute top-2 right-2 justify-center items-center w-8 h-8 rounded-full dark:text-white hover:bg-coolgray-300">
                                <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div class="relative w-auto">
                            <x-callout type="warning" title="{{ __('service.port_requirement_warning') }}" class="mb-4">
                                {!! __('service.port_requirement_warning_body', ['port' => $requiredPort]) !!}
                            </x-callout>

                            <x-callout type="danger" title="{{ __('service.consequences_title') }}" class="mb-4">
                                <ul class="mt-2 ml-4 list-disc">
                                    <li>{{ __('service.consequences_unreachable') }}</li>
                                    <li>{{ __('service.consequences_proxy') }}</li>
                                    <li>{{ __('service.consequences_env') }}</li>
                                    <li>{{ __('service.consequences_fail') }}</li>
                                </ul>
                            </x-callout>

                            <div class="flex flex-wrap gap-2 justify-between mt-4">
                                <x-forms.button @click="modalOpen = false; $wire.call('cancelRemovePort')"
                                    class="w-auto dark:bg-coolgray-200 dark:hover:bg-coolgray-300">
                                    {{ __('service.cancel_keep_port') }}
                                </x-forms.button>
                                <x-forms.button wire:click="confirmRemovePort" @click="modalOpen = false" class="w-auto"
                                    isError>
                                    {{ __('service.remove_port_anyway') }}
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</div>

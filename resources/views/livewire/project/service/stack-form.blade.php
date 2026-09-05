<form wire:submit.prevent="submit" class="application-settings-form flex flex-col gap-6">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section title="Service details"
        description="Manage the identity and Compose configuration for this service.">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (isDev())
                    <x-status-badge label="Parser {{ $service->compose_parsing_version }}" type="neutral" />
                @endif
                @can('update', $service)
                    <x-modal-input buttonTitle="Edit Compose file" title="Docker Compose" :closeOutside="false"
                        :isLarge="true">
                        <x-slot:headerActions>
                            <div x-data="{ preview: false, validating: false, saving: false }"
                                @compose-validate-finished.window="validating = false"
                                @compose-save-finished.window="saving = false" class="flex w-full items-center gap-2 overflow-x-auto sm:w-auto">
                                <x-forms.button
                                    @click="preview = !preview; $dispatch('compose-preview-toggle')">
                                    <x-reicon name="eye" class="size-3.5" />
                                    <span x-text="preview ? 'Back to source Compose' : 'Preview generated Compose'"></span>
                                </x-forms.button>
                                @if (blank($service->service_type))
                                    <x-forms.button @click="validating = true; $dispatch('compose-validate')"
                                        x-bind:disabled="validating">
                                        <x-loading-on-button x-show="validating" x-cloak />
                                        Validate
                                    </x-forms.button>
                                @endif
                                <x-forms.button @click="saving = true; $dispatch('compose-save')"
                                    x-bind:disabled="saving" isHighlighted>
                                    <x-loading-on-button x-show="saving" x-cloak />
                                    Save changes
                                </x-forms.button>
                            </div>
                        </x-slot:headerActions>
                        <livewire:project.service.edit-compose serviceId="{{ $service->id }}" />
                    </x-modal-input>
                @endcan
                <x-modal-input title="Resource details" buttonTitle="Details">
                    <livewire:project.shared.resource-details :resource="$service" />
                </x-modal-input>
            </div>
        </x-slot:actions>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input canGate="update" :canResource="$service" id="name" required label="Service name"
                placeholder="My WordPress site" />
            <x-forms.input canGate="update" :canResource="$service" id="description" label="Description" />
        </div>
    </x-application.settings-section>

    <x-application.settings-section title="Network"
        description="Control whether this Compose stack joins Coolify's predefined network.">
        <x-forms.listbox canGate="update" :canResource="$service" id="connectToDockerNetwork" label="Network attachment" live onChange="instantSave"
            :disabled="! auth()->user()->can('update', $service)" :options="[
                ['value' => false, 'label' => 'Use the stack network only'],
                ['value' => true, 'label' => 'Connect to the predefined Coolify network'],
            ]" />
    </x-application.settings-section>

    @if ($fields->count() > 0)
        <x-application.settings-section title="Service configuration"
            description="Template-specific values exposed by this service.">
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($fields as $serviceName => $field)
                    <div>
                        <div class="mb-1.5 flex items-center gap-1.5 text-[12px] font-medium">
                            <span>
                                @if (filled(data_get($field, 'serviceName')))
                                    {{ data_get($field, 'serviceName') }} ·
                                @endif
                                {{ data_get($field, 'name') }}
                            </span>
                            @if (data_get($field, 'customHelper'))
                                <x-helper helper="{{ data_get($field, 'customHelper') }}" />
                            @else
                                <x-helper helper="Variable name: {{ $serviceName }}" />
                            @endif
                        </div>
                        @if ($isPasswordHiddenForMember && data_get($field, 'isPassword'))
                            <x-forms.input disabled value="Hidden (only admins can view)" />
                        @else
                            <x-forms.input canGate="update" :canResource="$service"
                                type="{{ data_get($field, 'isPassword') ? 'password' : 'text' }}"
                                required="{{ str(data_get($field, 'rules'))?->contains('required') }}"
                                id="fields.{{ $serviceName }}.value" />
                        @endif
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>
    @endif
</form>

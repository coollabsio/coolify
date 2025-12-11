<div>
    <form wire:submit='submit' class="flex flex-col items-center gap-4 p-4 bg-white border lg:items-start dark:bg-base dark:border-coolgray-300 border-neutral-200">
        @if ($isReadOnly)
            @if (!$storage->isServiceResource() && !$storage->isDockerComposeResource())
                <div class="w-full p-2 text-sm rounded bg-warning/10 text-warning">
                    This volume is mounted as read-only and cannot be modified from the UI.
                </div>
            @endif
            @if ($isFirst)
                <div class="flex gap-2 items-end w-full  md:flex-row flex-col">
                    @if (
                        $storage->resource_type === 'App\Models\ServiceApplication' ||
                            $storage->resource_type === 'App\Models\ServiceDatabase')
                        <x-forms.input id="name" label="Volume Name" required readonly
                            helper="Warning: Changing the volume name after the initial start could cause problems. Only use it when you know what are you doing." />
                    @else
                        <x-forms.input id="name" label="Volume Name" required readonly
                            helper="Warning: Changing the volume name after the initial start could cause problems. Only use it when you know what are you doing." />
                    @endif
                    @if ($isService || $startedAt)
                        <x-forms.input id="hostPath" readonly helper="Directory on the host system."
                            label="Source Path"
                            helper="Warning: Changing the source path after the initial start could cause problems. Only use it when you know what are you doing." />
                        <x-forms.input id="mountPath" label="Destination Path"
                            helper="Directory inside the container." required readonly />
                    @else
                        <x-forms.input id="hostPath" readonly helper="Directory on the host system."
                            label="Source Path"
                            helper="Warning: Changing the source path after the initial start could cause problems. Only use it when you know what are you doing." />
                        <x-forms.input id="mountPath" label="Destination Path"
                            helper="Directory inside the container." required readonly />
                    @endif
                </div>
            @else
                <div class="flex gap-2 items-end w-full">
                    <x-forms.input id="name" required readonly />
                    <x-forms.input id="hostPath" readonly />
                    <x-forms.input id="mountPath" required readonly />
                </div>
            @endif
        @else
            @can('update', $resource)
                @if ($isFirst)
                    <div class="flex gap-2 items-end w-full">
                        <x-forms.input id="name" label="Volume Name" required />
                        <x-forms.input id="hostPath" helper="Directory on the host system." label="Source Path" />
                        <x-forms.input id="mountPath" label="Destination Path"
                            helper="Directory inside the container." required />
                    </div>
                @else
                    <div class="flex gap-2 items-end w-full">
                        <x-forms.input id="name" required />
                        <x-forms.input id="hostPath" />
                        <x-forms.input id="mountPath" required />
                    </div>
                @endif

                {{-- Advanced Options - Ownership Settings --}}
                <div x-data="{ showAdvanced: @js($chown || $chmod) }" class="w-full">
                    <button type="button" @click="showAdvanced = !showAdvanced"
                        class="flex items-center gap-1 text-sm text-neutral-400 hover:text-neutral-300">
                        <span x-text="showAdvanced ? 'Hide' : 'Show'"></span> Advanced Options
                        <svg x-show="!showAdvanced" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                        <svg x-show="showAdvanced" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                        </svg>
                    </button>

                    <div x-show="showAdvanced" x-collapse class="mt-4 space-y-4">
                        <div class="flex gap-2 md:flex-row flex-col">
                            <x-forms.input id="chown" label="Owner (user:group)"
                                placeholder="1000:1000 or www-data:www-data"
                                helper="Set ownership for the volume directory. Leave empty to use defaults." />
                            <x-forms.input id="chmod" label="Permissions" placeholder="755"
                                helper="Set permissions (e.g., 755, 777). Leave empty to use defaults." />
                        </div>
                        <div class="flex gap-4 flex-wrap">
                            <x-forms.checkbox id="recursive" label="Apply recursively"
                                helper="Apply ownership/permissions to all files inside the directory. Use with caution on existing data." />
                            <x-forms.checkbox id="applyOwnership" label="Apply ownership on deploy"
                                helper="When enabled, ownership/permissions are applied on every deployment. Disable to prevent overwriting manual changes." />
                        </div>
                    </div>
                </div>

                <div class="flex gap-2">
                    <x-forms.button type="submit">
                        Update
                    </x-forms.button>
                    <x-modal-confirmation title="Confirm persistent storage deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete" :actions="[
                            'The selected persistent storage/volume will be permanently deleted.',
                            'If the persistent storage/volume is actvily used by a resource data will be lost.',
                        ]" confirmationText="{{ $storage->name }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Storage Name below"
                        shortConfirmationLabel="Storage Name" />
                </div>
            @else
                @if ($isFirst)
                    <div class="flex gap-2 items-end w-full">
                        <x-forms.input id="name" label="Volume Name" required disabled />
                        <x-forms.input id="hostPath" helper="Directory on the host system." label="Source Path"
                            disabled />
                        <x-forms.input id="mountPath" label="Destination Path"
                            helper="Directory inside the container." required disabled />
                    </div>
                @else
                    <div class="flex gap-2 items-end w-full">
                        <x-forms.input id="name" required disabled />
                        <x-forms.input id="hostPath" disabled />
                        <x-forms.input id="mountPath" required disabled />
                    </div>
                @endif
            @endcan
        @endif
    </form>
</div>

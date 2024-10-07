<div>
    <form wire:submit='submit' class="flex flex-col gap-2 xl:items-end xl:flex-row">
        @if ($isReadOnly)
            @if ($isFirst)
                @if (
                    $storage->resource_type === 'App\Models\ServiceApplication' ||
                        $storage->resource_type === 'App\Models\ServiceDatabase')
                    <x-forms.input id="storage.name" label="Volume Name" required readonly
                        helper="Warning: Changing the volume name after the initial start could cause problems. Only use it when you know what are you doing." />
                @else
                    <x-forms.input id="storage.name" label="Volume Name" required
                        helper="Warning: Changing the volume name after the initial start could cause problems. Only use it when you know what are you doing." />
                @endif
                @if ($isService || $startedAt)
                    <x-forms.input id="storage.host_path" readonly helper="Directory on the host system."
                        label="Source Path"
                        helper="Warning: Changing the source path after the initial start could cause problems. Only use it when you know what are you doing." />
                    <x-forms.input id="storage.mount_path" label="Destination Path"
                        helper="Directory inside the container." required readonly />
                @else
                    <x-forms.input id="storage.host_path" helper="Directory on the host system." label="Source Path"
                        helper="Warning: Changing the source path after the initial start could cause problems. Only use it when you know what are you doing." />
                    <x-forms.input id="storage.mount_path" label="Destination Path"
                        helper="Directory inside the container." required readonly />
                    <x-forms.button type="submit">
                        Update
                    </x-forms.button>
                @endif
            @else
                <x-forms.input id="storage.name" required readonly />
                <x-forms.input id="storage.host_path" readonly />
                <x-forms.input id="storage.mount_path" required readonly />
            @endif
        @else
            @if ($isFirst)
                <x-forms.input id="storage.name" label="Volume Name" required />
                <x-forms.input id="storage.host_path" helper="Directory on the host system." label="Source Path" />
                <x-forms.input id="storage.mount_path" label="Destination Path" helper="Directory inside the container."
                    required />
            @else
                <x-forms.input id="storage.name" required />
                <x-forms.input id="storage.host_path" />
                <x-forms.input id="storage.mount_path" required />
            @endif
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
                    shortConfirmationLabel="Storage Name" step3ButtonText="Permanently Delete" />
            </div>
        @endif
    </form>
</div>

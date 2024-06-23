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
                @else
                    <x-forms.input id="storage.host_path" helper="Directory on the host system." label="Source Path"
                        helper="Warning: Changing the source path after the initial start could cause problems. Only use it when you know what are you doing." />
                @endif
                <x-forms.input id="storage.mount_path" label="Destination Path" helper="Directory inside the container."
                    required readonly />
                <x-forms.button type="submit">
                    Update
                </x-forms.button>
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
                <x-modal-confirmation isErrorButton buttonTitle="Delete">
                    This storage will be deleted <span class="font-bold dark:text-warning">{{ $storage->name }}</span>.
                    It
                    is
                    not
                    reversible. <br>Please think again.
                </x-modal-confirmation>
            </div>
        @endif
    </form>
</div>

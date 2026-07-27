<div>
    <x-slot:title>
        {{ data_get_str($storage, 'name')->limit(20) }} | S3 Storage | Coolify
    </x-slot>

    <x-dashboard.navbar section="storage" :parameters="['storage_uuid' => $storage->uuid]">
        <x-slot:actions>
            <x-status-badge :status="$storage->is_usable ? 'Ready' : 'Not usable'"
                :type="$storage->is_usable ? 'success' : 'error'" />
            @can('delete', $storage)
                <x-modal-confirmation title="Confirm Storage Deletion?" isErrorButton buttonTitle="Delete"
                    submitAction="delete({{ $storage->id }})" :actions="array_filter([
                        'The selected storage location will be permanently deleted from Coolify.',
                        $backupCount > 0
                            ? $backupCount.' backup schedule(s) will stop saving to S3. Existing objects in this storage will not be deleted.'
                            : null,
                    ])"
                    confirmationText="{{ $storage->name }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Storage Name below"
                    shortConfirmationLabel="Storage Name" :confirmWithPassword="false"
                    step2ButtonText="Permanently Delete" />
            @endcan
        </x-slot:actions>
    </x-dashboard.navbar>

    @if ($currentRoute === 'storage.show')
        <livewire:storage.form :storage="$storage" />
    @elseif ($currentRoute === 'storage.resources')
        <livewire:storage.resources :storage="$storage" :key="'resources-'.$storage->uuid" />
    @endif
</div>

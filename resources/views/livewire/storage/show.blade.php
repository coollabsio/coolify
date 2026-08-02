<div>
    <x-slot:title>
        {{ data_get_str($storage, 'name')->limit(20) }} | S3 Storage | Coolify
    </x-slot>

    <div class="flex flex-col">
        <header class="order-1 mb-4 min-w-0 lg:order-2 lg:mb-8">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">
                {{ $storage->name }}
            </h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ filled($storage->description) ? $storage->description : 'S3-compatible backup destination' }}
            </p>
        </header>

        <div class="order-2 lg:order-1">
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
        </div>

        <div class="order-3">
            @if ($currentRoute === 'storage.show')
                <livewire:storage.form :storage="$storage" />
            @elseif ($currentRoute === 'storage.resources')
                <livewire:storage.resources :storage="$storage" :key="'resources-'.$storage->uuid" />
            @endif
        </div>
    </div>
</div>

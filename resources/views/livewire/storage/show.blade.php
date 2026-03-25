<div>
    <x-slot:title>
        {{ data_get_str($storage, 'name')->limit(10) }} >Storages | Coolify
    </x-slot>

    <div class="flex items-center gap-2">
        <h1>Storage Details</h1>
        @if ($storage->is_usable)
            <span class="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                Usable
            </span>
        @else
            <span class="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                Not Usable
            </span>
        @endif
        <x-forms.button canGate="update" :canResource="$storage" wire:click="$dispatch('submitStorage')" :disabled="$currentRoute !== 'storage.show'">Save</x-forms.button>
        @can('delete', $storage)
            <x-modal-confirmation title="Confirm Storage Deletion?" isErrorButton buttonTitle="Delete"
                submitAction="delete({{ $storage->id }})" :actions="array_filter([
                    'The selected storage location will be permanently deleted from Coolify.',
                    $backupCount > 0
                        ? $backupCount . ' backup schedule(s) will be updated to no longer save to S3 and will only store backups locally on the server.'
                        : null,
                ])" confirmationText="{{ $storage->name }}"
                confirmationLabel="Please confirm the execution of the actions by entering the Storage Name below"
                shortConfirmationLabel="Storage Name" :confirmWithPassword="false" step2ButtonText="Permanently Delete" />
        @endcan
    </div>
    <div class="subtitle">{{ $storage->name }}</div>

    <div class="navbar-main">
        <nav class="flex shrink-0 gap-6 items-center whitespace-nowrap scrollbar min-h-10">
            <a class="{{ request()->routeIs('storage.show') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('storage.show', ['storage_uuid' => $storage->uuid]) }}">
                General
            </a>
            <a class="{{ request()->routeIs('storage.resources') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('storage.resources', ['storage_uuid' => $storage->uuid]) }}">
                Resources
            </a>
        </nav>
    </div>

    <div class="pt-4">
    @if ($currentRoute === 'storage.show')
        <livewire:storage.form :storage="$storage" />
    @elseif ($currentRoute === 'storage.resources')
        <livewire:storage.resources :storage="$storage" :key="'resources-'.uniqid()" />
    @endif
    </div>
</div>

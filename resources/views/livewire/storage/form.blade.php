<div>
    <form class="flex flex-col gap-10 pb-6" wire:submit='submit'>
        <div class="form-card">
            <div class="form-section-title">
                <h1>Storage Details</h1>
                <div class="flex items-center gap-2">
                    <x-forms.button canGate="update" :canResource="$storage" type="submit">Save</x-forms.button>
                    @can('delete', $storage)
                        <x-modal-confirmation title="Confirm Storage Deletion?" isErrorButton buttonTitle="Delete"
                            submitAction="delete({{ $storage->id }})" :actions="[
                                'The selected storage location will be permanently deleted from Coolify.',
                                'If the storage location is in use by any backup jobs those backup jobs will only store the backup locally on the server.',
                            ]" confirmationText="{{ $storage->name }}"
                            confirmationLabel="Please confirm the execution of the actions by entering the Storage Name below"
                            shortConfirmationLabel="Storage Name" :confirmWithPassword="false" step2ButtonText="Permanently Delete" />
                    @endcan
                </div>
            </div>
            <div class="mt-1 flex items-center gap-2 text-sm text-neutral-500 dark:text-neutral-400">
                <span>{{ $storage->name }}</span>
                <span>-</span>
                <span>Current Status:</span>
                @if ($isUsable)
                    <span
                        class="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                        Usable
                    </span>
                @else
                    <span
                        class="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                        Not Usable
                    </span>
                @endif
            </div>
        <div class="flex flex-col gap-10 mt-4">
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$storage" label="Name" id="name" />
                <x-forms.input canGate="update" :canResource="$storage" label="Description" id="description" />
            </div>
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$storage" required label="Endpoint" id="endpoint" />
                <x-forms.input canGate="update" :canResource="$storage" required label="Bucket" id="bucket" />
                <x-forms.input canGate="update" :canResource="$storage" required label="Region" id="region" />
            </div>
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$storage" required type="password" label="Access Key"
                    id="key" />
                <x-forms.input canGate="update" :canResource="$storage" required type="password" label="Secret Key"
                    id="secret" />
            </div>
        </div>
        @can('validateConnection', $storage)
            <x-forms.button class="mt-4" isHighlighted wire:click="testConnection">
                Validate Connection
            </x-forms.button>
        @endcan
        </div>
    </form>
</div>

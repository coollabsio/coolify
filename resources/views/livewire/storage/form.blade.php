<div>
    <form class="flex flex-col gap-2 pb-6" wire:submit='submit'>
        <div class="flex items-start gap-2">
            <div class="">
                <h1>Storage Details</h1>
                <div class="subtitle">{{ $storage->name }}</div>
                <div class="flex items-center gap-2 pb-4">
                    <div>Current Status:</div>
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
            </div>
            <x-forms.button canGate="update" :canResource="$storage" type="submit">{{ __('common.save') }}</x-forms.button>

            @can('delete', $storage)
                <x-modal-confirmation title="{{ __('modal.confirm_storage_deletion') }}" isErrorButton buttonTitle="{{ __('modal.delete_storage') }}"
                    submitAction="delete({{ $storage->id }})" :actions="[
                        'The selected storage location will be permanently deleted from Coolify.',
                        'If the storage location is in use by any backup jobs those backup jobs will only store the backup locally on the server.',
                    ]" confirmationText="{{ $storage->name }}"
                    confirmationLabel="{{ __('modal.storage_name_confirmation') }}"
                    shortConfirmationLabel="{{ __('storage.name') }}" :confirmWithPassword="false" step2ButtonText="{{ __('button.permanently_delete') }}" />
            @endcan
        </div>
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
        @can('validateConnection', $storage)
            <x-forms.button class="mt-4" isHighlighted wire:click="testConnection">
                Validate Connection
            </x-forms.button>
        @endcan
    </form>
</div>

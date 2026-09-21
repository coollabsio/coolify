<div>
    @if ($modalMode)
        <div class="relative">
        <form wire:submit="changeAgeKey" class="flex flex-col gap-4"
            wire:loading.class="pointer-events-none opacity-50" wire:target="delete">
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input canGate="update" :canResource="$age_key" id="name" label="Name" required />
                <x-forms.input canGate="update" :canResource="$age_key" id="description" label="Description" />
                <div class="lg:col-span-2">
                    <x-forms.input canGate="update" :canResource="$age_key" id="publicKeyValue" monospace
                        label="Public key" required />
                </div>
            </div>
            <div class="flex items-center justify-between gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                @can('delete', $age_key)
                    <x-modal-confirmation title="Confirm Age Key Deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete" :disabled="$isInUse" :disabledTooltip="$deleteDisabledReason"
                        :actions="['This age key will be permanently deleted.']" confirmationText="{{ $age_key->name }}"
                        :confirmWithPassword="false" step2ButtonText="Delete age key" />
                @endcan
                <x-forms.button type="submit" isHighlighted>Save changes</x-forms.button>
            </div>
        </form>
        <div wire:loading.flex wire:target="delete"
            class="absolute inset-0 z-10 items-center justify-center rounded-lg bg-white/50 dark:bg-black/40">
            <x-loading text="Deleting age key..." />
        </div>
        </div>
    @else
    <x-slot:title>
        {{ $age_key->name }} | Age Keys | Coolify
    </x-slot>

    <x-security.settings-layout>
        <x-slot:actions>
            @can('delete', $age_key)
                <x-modal-confirmation title="Confirm Age Key Deletion?" isErrorButton buttonTitle="Delete"
                    submitAction="delete" :disabled="$isInUse"
                    :disabledTooltip="$deleteDisabledReason" :actions="[
                        'This age key will be permanently deleted.',
                        'Backup schedules using it will have encryption disabled.',
                    ]"
                    confirmationText="{{ $age_key->name }}"
                    confirmationLabel="Enter the age key name to confirm deletion"
                    shortConfirmationLabel="Age key name" :confirmWithPassword="false"
                    step2ButtonText="Delete age key" />
            @endcan
        </x-slot:actions>


    <form wire:submit="changeAgeKey" class="application-settings-form">
        <x-unsaved-bar action="changeAgeKey" />
        <x-application.settings-section title="General">
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input canGate="update" :canResource="$age_key" id="name" label="Name" required />
                <x-forms.input canGate="update" :canResource="$age_key" id="description"
                    label="Description" />
                <div class="lg:col-span-2">
                    <x-forms.input canGate="update" :canResource="$age_key" id="publicKeyValue" monospace
                        label="Public key" required />
                </div>
            </div>
        </x-application.settings-section>
    </form>
    </x-security.settings-layout>
    @endif
</div>

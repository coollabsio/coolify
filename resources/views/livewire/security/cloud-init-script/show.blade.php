<div>
    @if ($modalMode)
        <form wire:submit="save" class="flex flex-col gap-4">
            <x-forms.input canGate="update" :canResource="$cloudInitScript" id="name" label="Script name" required />
            <x-forms.textarea canGate="update" :canResource="$cloudInitScript" id="script"
                label="Script content" rows="16" monospace
                helper="Cloud-config YAML or another script accepted by your provider." required />
            <div class="flex items-center justify-between gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                @can('delete', $cloudInitScript)
                    <x-modal-confirmation title="Confirm Script Deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete" :actions="['This cloud-init script will be permanently deleted.']"
                        confirmationText="{{ $cloudInitScript->name }}" :confirmWithPassword="false"
                        step2ButtonText="Delete script" />
                @endcan
                <x-forms.button type="submit" isHighlighted>Save changes</x-forms.button>
            </div>
        </form>
    @else
    <x-slot:title>
        {{ $cloudInitScript->name }} | Cloud-Init Scripts | Coolify
    </x-slot>

    <x-security.settings-layout>
        <x-slot:actions>
            @can('delete', $cloudInitScript)
                <x-modal-confirmation title="Confirm Script Deletion?" isErrorButton buttonTitle="Delete"
                    submitAction="delete" :actions="[
                        'This cloud-init script will be permanently deleted.',
                        'This action cannot be undone.',
                    ]" confirmationText="{{ $cloudInitScript->name }}"
                    confirmationLabel="Enter the script name to confirm deletion"
                    shortConfirmationLabel="Script name" :confirmWithPassword="false"
                    step2ButtonText="Delete script" />
            @endcan
        </x-slot:actions>


    <form wire:submit="save" class="application-settings-form">
        <x-unsaved-bar action="save" />
        <x-application.settings-section title="General"
            description="Edit the reusable initialization script used during cloud server creation.">
            <div class="flex flex-col gap-4">
                <x-forms.input canGate="update" :canResource="$cloudInitScript" id="name"
                    label="Script name" required />
                <x-forms.textarea canGate="update" :canResource="$cloudInitScript" id="script"
                    label="Script content" rows="18" monospace
                    helper="Cloud-config YAML or another script accepted by your provider." required />
            </div>
        </x-application.settings-section>
    </form>
    </x-security.settings-layout>
    @endif
</div>

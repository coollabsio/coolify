<div>
    <x-slot:title>
        Cloud-Init Script | Coolify
    </x-slot>
    <x-security.navbar />
    <form class="flex flex-col" wire:submit="save">
        <div class="flex items-start gap-2">
            <h2 class="pb-4">Cloud-Init Script</h2>
            <x-forms.button canGate="update" :canResource="$cloudInitScript" type="submit">
                Save
            </x-forms.button>
            @can('delete', $cloudInitScript)
                <x-modal-confirmation title="Confirm Script Deletion?" isErrorButton buttonTitle="Delete"
                    submitAction="delete" :actions="[
                        'This cloud-init script will be permanently deleted.',
                        'This action cannot be undone.',
                    ]" confirmationText="{{ $cloudInitScript->name }}"
                    confirmationLabel="Please confirm the deletion by entering the script name below"
                    shortConfirmationLabel="Script Name" :confirmWithPassword="false" step2ButtonText="Delete Script" />
            @endcan
        </div>
        <div class="flex flex-col gap-2">
            <x-forms.input canGate="update" :canResource="$cloudInitScript" id="name" label="Script Name" required />
            <x-forms.textarea canGate="update" :canResource="$cloudInitScript" id="script" label="Script Content" rows="12"
                helper="Enter your cloud-init script. Supports cloud-config YAML format." required />
        </div>
    </form>
</div>

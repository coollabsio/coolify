@props(['attachment'])

<div class="flex flex-wrap gap-2">
    <x-forms.button wire:click="editAttachment({{ $attachment->id }})" :disabled="$attachment->is_runtime_discovered">Edit</x-forms.button>
    @if ($attachment->status?->value === 'attached')
        <x-forms.button wire:click="disconnectAttachment({{ $attachment->id }})" :disabled="! $attachment->is_managed || $attachment->is_runtime_discovered">
            Disconnect
        </x-forms.button>
    @else
        <x-forms.button isHighlighted wire:click="connectAttachment({{ $attachment->id }})" :disabled="! $attachment->is_managed || $attachment->is_runtime_discovered">
            Connect
        </x-forms.button>
    @endif
    <x-forms.button wire:click="setPrimary({{ $attachment->id }})" :disabled="$attachment->is_runtime_discovered">Set primary</x-forms.button>
    <x-forms.button wire:click="toggleRequired({{ $attachment->id }})" :disabled="$attachment->is_runtime_discovered">{{ $attachment->is_required ? 'Optional' : 'Required' }}</x-forms.button>
    <x-modal-confirmation title="Remove Network Configuration?" buttonTitle="Remove configuration" isErrorButton
        submitAction="removeAttachment({{ $attachment->id }})"
        :actions="[
            'Remove this network configuration from Coolify.',
            'Connected networks must be disconnected first.',
        ]"
        confirmationText="{{ $attachment->dockerNetwork?->docker_network_name ?? 'Remove network configuration' }}"
        confirmationLabel="Please confirm by entering the Docker network name below"
        shortConfirmationLabel="Docker network name"
        :confirmWithPassword="false"
        :disabled="$attachment->is_runtime_discovered"
        step2ButtonText="Remove" />
</div>

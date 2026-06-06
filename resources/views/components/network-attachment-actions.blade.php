@props(['attachment'])

<div class="flex flex-wrap gap-2">
    <x-forms.button wire:click="editAttachment({{ $attachment->id }})">Edit</x-forms.button>
    @if ($attachment->status?->value === 'attached')
        <x-forms.button wire:click="disconnectAttachment({{ $attachment->id }})" :disabled="! $attachment->is_managed || $attachment->is_runtime_discovered">
            Disconnect
        </x-forms.button>
    @else
        <x-forms.button isHighlighted wire:click="connectAttachment({{ $attachment->id }})" :disabled="! $attachment->is_managed || $attachment->is_runtime_discovered">
            Connect
        </x-forms.button>
    @endif
    <x-forms.button wire:click="setPrimary({{ $attachment->id }})">Set primary</x-forms.button>
    <x-forms.button wire:click="toggleRequired({{ $attachment->id }})">{{ $attachment->is_required ? 'Optional' : 'Required' }}</x-forms.button>
    <x-forms.button x-on:click="if (confirm('Remove this network configuration from Coolify? Connected networks must be disconnected first.')) { $wire.removeAttachment({{ $attachment->id }}) }">
        Remove configuration
    </x-forms.button>
</div>

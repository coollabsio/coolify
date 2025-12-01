<div wire:init="refreshBackupStatus">
    @if ($isRunning)
        <x-forms.button disabled class="opacity-50 cursor-not-allowed">
            <x-loading class="w-4 h-4 mr-2" /> Backup Running...
        </x-forms.button>
    @else
        <x-forms.button wire:click='backupNow'>Backup Now</x-forms.button>
    @endif
</div>

<div x-on:open-backup-download.window="window.location.href = $event.detail.url">
    @if (count($pendingDownloads) > 0)
        <div wire:poll.3s="checkDownloads" class="hidden"></div>
    @endif
    @if ($actionsInHeader && $backup)
        @can('update', $resource)
            @teleport('#resource-action-hud-slot')
                <div class="order-first mr-1 hidden items-center gap-1 xl:flex">
                    @if ($enabled)
                        <x-forms.button type="button" wire:click="toggleEnabled">Disable backup</x-forms.button>
                    @else
                        <x-forms.button type="button" wire:click="toggleEnabled" isHighlighted>Enable backup</x-forms.button>
                    @endif
                    <x-forms.button type="button" wire:click="backupNow">Back up now</x-forms.button>
                </div>
            @endteleport
        @endcan
    @endif
    @if ($section === 'executions')
        @if ($backup && $executions->onFirstPage())
            <div wire:poll.5000ms class="hidden"></div>
        @endif
        @include('livewire.project.shared.storages.volume-backups.executions')
    @elseif ($section === 'danger')
        @include('livewire.project.shared.storages.volume-backups.danger')
    @else
        @include('livewire.project.shared.storages.volume-backups.general')
    @endif

    @script
    <script>
        window.download_volume_backup_file = function(executionId) {
            @if ($s3Downloads)
                $wire.downloadBackup(executionId);
            @else
                window.open('/download/volume-backup/' + executionId, '_blank');
            @endif
        }
    </script>
    @endscript
</div>

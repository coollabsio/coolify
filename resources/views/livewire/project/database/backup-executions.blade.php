<div wire:init='refreshBackupExecutions'>
    @isset($backup)
        <div class="flex items-center gap-2">
            <h3 class="py-4">Executions</h3>
            <x-forms.button wire:click='cleanupFailed'>Cleanup Failed Backups</x-forms.button>
        </div>
        <div class="flex flex-col-reverse gap-2">
            @forelse($executions as $execution)
                <form wire:key="{{ data_get($execution, 'id') }}"
                    class="relative flex flex-col p-4 bg-white box-without-bg dark:bg-coolgray-100"
                    @class([
                        'border-green-500' => data_get($execution, 'status') === 'success',
                        'border-red-500' => data_get($execution, 'status') === 'failed',
                    ])>
                    @if (data_get($execution, 'status') === 'running')
                        <div class="absolute top-2 right-2">
                            <x-loading />
                        </div>
                    @endif
                    <div>Database: {{ data_get($execution, 'database_name', 'N/A') }}</div>
                    <div>Status: {{ data_get($execution, 'status') }}</div>
                    <div>Started At: {{ data_get($execution, 'created_at') }}</div>
                    @if (data_get($execution, 'message'))
                        <div>Message: {{ data_get($execution, 'message') }}</div>
                    @endif
                    <div>Size: {{ data_get($execution, 'size') }} B /
                        {{ round((int) data_get($execution, 'size') / 1024, 2) }}
                        kB / {{ round((int) data_get($execution, 'size') / 1024 / 1024, 3) }} MB
                    </div>
                    <div>Location: {{ data_get($execution, 'filename', 'N/A') }}</div>
                    <div class="flex gap-2">
                        <div class="flex-1"></div>
                        @if (data_get($execution, 'status') === 'success')
                            <x-forms.button class=" dark:hover:bg-coolgray-400"
                                x-on:click="download_file('{{ data_get($execution, 'id') }}')">Download</x-forms.button>
                        @endif
                        <x-modal-confirmation isErrorButton action="deleteBackup({{ data_get($execution, 'id') }})">
                            <x-slot:button-title>
                                Delete
                            </x-slot:button-title>
                            This will delete this backup. It is not reversible.<br>Please think again.
                        </x-modal-confirmation>
                    </div>
                </form>

            @empty
                <div>No executions found.</div>
            @endforelse
        </div>
        <script>
            function download_file(executionId) {
                window.open('/download/backup/' + executionId, '_blank');
            }
        </script>
    @endisset

</div>

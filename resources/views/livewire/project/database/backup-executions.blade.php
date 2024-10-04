<div wire:init='refreshBackupExecutions'>
    @isset($backup)
        <div class="flex items-center gap-2">
            <h3 class="py-4">Executions</h3>
            <x-forms.button wire:click='cleanupFailed'>Cleanup Failed Backups</x-forms.button>
        </div>
        <div class="flex flex-col gap-4">
            @forelse($executions as $execution)
                <div wire:key="{{ data_get($execution, 'id') }}" @class([
                    'flex flex-col border-l-2 transition-colors p-4 ',
                    'bg-white dark:bg-coolgray-100 ',
                    'text-black dark:text-white',
                    'border-green-500' => data_get($execution, 'status') === 'success',
                    'border-red-500' => data_get($execution, 'status') === 'failed',
                    'border-yellow-500' => data_get($execution, 'status') === 'running',
                ])>
                    @if (data_get($execution, 'status') === 'running')
                        <div class="absolute top-2 right-2">
                            <x-loading />
                        </div>
                    @endif
                    <div class="text-gray-700 dark:text-gray-300 font-semibold mb-1">Status:
                        {{ data_get($execution, 'status') }}</div>
                    <div class="text-gray-600 dark:text-gray-400 text-sm">
                        Started At: {{ $this->formatDateInServerTimezone(data_get($execution, 'created_at')) }}
                    </div>
                    <div class="text-gray-600 dark:text-gray-400 text-sm">
                        Database: {{ data_get($execution, 'database_name', 'N/A') }}
                    </div>
                    <div class="text-gray-600 dark:text-gray-400 text-sm">
                        Size: {{ data_get($execution, 'size') }} B /
                        {{ round((int) data_get($execution, 'size') / 1024, 2) }} kB /
                        {{ round((int) data_get($execution, 'size') / 1024 / 1024, 3) }} MB
                    </div>
                    <div class="text-gray-600 dark:text-gray-400 text-sm">
                        Location: {{ data_get($execution, 'filename', 'N/A') }}
                    </div>
                    @if (data_get($execution, 'message'))
                        <div class="mt-2 p-2 bg-gray-100 dark:bg-coolgray-200 rounded">
                            <pre class="whitespace-pre-wrap text-sm">{{ data_get($execution, 'message') }}</pre>
                        </div>
                    @endif
                    <div class="flex gap-2 mt-4">
                        @if (data_get($execution, 'status') === 'success')
                            <x-forms.button class="dark:hover:bg-coolgray-400"
                                x-on:click="download_file('{{ data_get($execution, 'id') }}')">Download</x-forms.button>
                        @endif
                        <x-modal-confirmation title="Confirm Backup Deletion?" buttonTitle="Delete" isErrorButton
                            submitAction="deleteBackup({{ data_get($execution, 'id') }})"
                            :actions="['This backup will be permanently deleted from local storage.']" confirmationText="{{ data_get($execution, 'filename') }}"
                            confirmationLabel="Please confirm the execution of the actions by entering the Backup Filename below"
                            shortConfirmationLabel="Backup Filename" step3ButtonText="Permanently Delete" />
                    </div>
                </div>
            @empty
                <div class="p-4 bg-gray-100 dark:bg-coolgray-100 rounded">No executions found.</div>
            @endforelse
        </div>
        <script>
            function download_file(executionId) {
                window.open('/download/backup/' + executionId, '_blank');
            }
        </script>
    @endisset
</div>

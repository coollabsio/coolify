<div class="flex flex-col-reverse gap-2">
    @forelse($executions as $execution)
        <form wire:key="{{ data_get($execution, 'id') }}"
            class="relative flex flex-col p-4 border-dotted border-1 bg-coolgray-100" @class([
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
            <div>Size: {{ data_get($execution, 'size') }} B / {{ round((int) data_get($execution, 'size') / 1024, 2) }}
                kB / {{ round((int) data_get($execution, 'size') / 1024 / 1024, 3) }} MB
            </div>
            <div>Location: {{ data_get($execution, 'filename', 'N/A') }}</div>
            <div class="flex gap-2">
                <div class="flex-1"></div>
                @if (data_get($execution, 'status') === 'success')
                    <x-forms.button class=" hover:bg-coolgray-400"
                        wire:click="download({{ data_get($execution, 'id') }})">Download</x-forms.button>
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

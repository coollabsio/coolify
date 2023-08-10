<div class="flex flex-col gap-2">
    @forelse($executions as $execution)
        <form class="border-1 bg-coolgray-300 p-2 border-dotted flex flex-col"
            @class([
                'border-green-500' => data_get($execution,'status') === 'success',
                'border-red-500' => data_get($execution,'status') === 'failed',
            ])>
            <div>Status: {{data_get($execution,'status')}}</div>
            @if(data_get($execution,'message'))
                <div>Message: {{data_get($execution,'message')}}</div>
            @endif
            <div>Size: {{data_get($execution,'size')}} B / {{round((int)data_get($execution,'size') / 1024,2)}}
                kB / {{round((int)data_get($execution,'size')/1024/1024,2)}} MB
            </div>
            <div>Location: {{data_get($execution,'filename')}}</div>
            <livewire:project.database.backup-execution :execution="$execution" :wire:key="$execution->id"/>
        </form>
    @empty
        <div>No logs found.</div>
    @endforelse

</div>

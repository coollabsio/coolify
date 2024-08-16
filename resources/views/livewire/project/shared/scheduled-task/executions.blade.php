<div class="flex flex-col-reverse gap-2">
    @forelse($executions as $execution)
        @if (data_get($execution, 'id') == $selectedKey)
            <div class="p-2">
                @if (data_get($execution, 'message'))
                    <div>
                        <pre>{{ data_get($execution, 'message') }}</pre>
                    </div>
                @else
                    <div>No output was recorded for this execution.</div>
                @endif
            </div>
        @endif
        <a wire:click="selectTask({{ data_get($execution, 'id') }})" @class([
            'flex flex-col border-l  transition-colors box-without-bg bg-coolgray-100 hover:bg-coolgray-200 cursor-pointer',
            'bg-coolgray-200 dark:text-white hover:bg-coolgray-200' =>
                data_get($execution, 'id') == $selectedKey,
            'border-green-500' => data_get($execution, 'status') === 'success',
            'border-red-500' => data_get($execution, 'status') === 'failed',
        ])>
            @if (data_get($execution, 'status') === 'running')
                <div class="absolute top-2 right-2">
                    <x-loading />
                </div>
            @endif
            <div>Status: {{ data_get($execution, 'status') }}</div>
            <div>Started At: 
                @php
                    $createdAt = data_get($execution, 'created_at', now());
                    $serverTimezone = $this->getServerTimezone();
                    ray('Created At:', $createdAt, 'Server Timezone:', $serverTimezone);
                    
                    $date = new DateTime($createdAt);
                    if ($serverTimezone) {
                        try {
                            $date->setTimezone(new DateTimeZone($serverTimezone));
                        } catch (\Exception $e) {
                            ray('Invalid timezone:', $serverTimezone);
                        }
                    }
                    echo $date->format('Y-m-d H:i:s T');
                @endphp
            </div>
        </a>
    @empty
        <div>No executions found.</div>
    @endforelse
</div>
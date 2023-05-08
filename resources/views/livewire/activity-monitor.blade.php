<div>
    @isset($this->activity)
        <span>Status: {{ $this->activity?->properties->get('status') }}</span>
        <pre class="flex flex-col-reverse w-full overflow-y-hidden"
            @if ($isPollingActive) wire:poll.750ms="polling" @endif>{{ \App\Actions\CoolifyTask\RunRemoteProcess::decodeOutput($this->activity) }}</pre>
    @else
        <span>Output will be here...</span>
    @endisset
</div>

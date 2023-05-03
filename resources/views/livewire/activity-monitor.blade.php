<div class="mt-8">
    @isset($this->activity)
        <span>Activity: {{ $this->activity?->id }}</span>
        <span>Status: {{ $this->activity?->properties->get('status') }}</span>
        <pre
                class="h-[400px] flex flex-col-reverse w-full bg-[#cbcbcb] overflow-y-scroll"
                @if($isPollingActive)
                    wire:poll.750ms="polling"
            @endif
        >{{
            \App\Actions\CoolifyTask\RunRemoteProcess::decodeOutput($this->activity)
        }}</pre>
    @else
        Not monitoring any activity.
    @endisset

</div>

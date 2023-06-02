<div>
    @if ($this->activity)
        @if ($header)
            <h2>Logs</h2>
        @endif
        <div
            class="scrollbar flex flex-col-reverse w-full overflow-y-auto border border-solid rounded border-coolgray-300 max-h-[32rem] p-4 text-xs text-white">
            @if ($isPollingActive)
                <span class="loading loading-bars"></span>
            @endif
            <pre class="font-mono whitespace-pre-wrap" @if ($isPollingActive) wire:poll.2000ms="polling" @endif>{{ \App\Actions\CoolifyTask\RunRemoteProcess::decodeOutput($this->activity) }}</pre>
            {{-- @else
            <pre class="whitespace-pre-wrap">Output will be here...</pre> --}}
        </div>
    @endif
</div>

@php use App\Actions\CoolifyTask\RunRemoteProcess; @endphp
<div>
    @if ($this->activity)
        @if ($header)
            <div class="flex gap-2 pb-2">
                <h3>Logs</h3>
                @if ($isPollingActive)
                    <x-loading/>
                @endif
            </div>
        @endif
        <div
            class="scrollbar flex flex-col-reverse w-full overflow-y-auto border border-solid rounded border-coolgray-300 max-h-[32rem] p-4 text-xs text-white">

            <pre class="font-mono whitespace-pre-wrap"
                 @if ($isPollingActive) wire:poll.2000ms="polling" @endif>{{ RunRemoteProcess::decodeOutput($this->activity) }}</pre>
        </div>
    @endif
</div>

<div x-init="$wire.getLogs">
    <div class="flex gap-2">
        <h2>Logs</h2>
        @if ($streamLogs)
            <span wire:poll.1000ms='getLogs(true)' class="loading loading-xs text-warning loading-spinner"></span>
        @endif
    </div>
    <div class="w-32">
        <x-forms.checkbox instantSave label="Stream Logs" id="streamLogs"></x-forms.checkbox>
    </div>
    <div class="container w-full pt-4 mx-auto">
        <div
            class="scrollbar flex flex-col-reverse w-full overflow-y-auto border border-solid rounded border-coolgray-300 max-h-[32rem] p-4 pt-6 text-xs text-white">

            <pre class="font-mono whitespace-pre-wrap">{{ $outputs }}</pre>
        </div>
    </div>
</div>

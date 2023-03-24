<div>
    <div>
        <label for="command">
            <input autofocus class="py-2 rounded ring-1" id="command" wire:model="command" type="text"
                wire:keydown.enter="runCommand" />
        </label>
        <button wire:click="runCommand">Run command</button>

        <button wire:click="runSleepingBeauty">Run sleeping beauty</button>
        <button wire:click="runDummyProjectBuild">Build DummyProject</button>
    </div>

    <div>
        <input id="manualKeepAlive" name="manualKeepAlive" type="checkbox" wire:model="manualKeepAlive">
        <label for="manualKeepAlive">Real-time logs</label>
        @if ($isKeepAliveOn || $manualKeepAlive)
            Polling...
        @endif
    </div>
    @isset($activity?->id)
        <div>
            Activity: <span>{{ $activity?->id ?? 'waiting' }}</span>
        </div>
        <pre style="width: 100%;overflow-y: scroll;" @if ($isKeepAliveOn || $manualKeepAlive) wire:poll.750ms="polling" @endif>{{ data_get($activity, 'description') }}</pre>
        <div>
            <div>Details:</div>
            <pre style="width: 100%;overflow-y: scroll;">{{ json_encode(data_get($activity, 'properties'), JSON_PRETTY_PRINT) }}</pre>
        </div>
    @endisset
</div>

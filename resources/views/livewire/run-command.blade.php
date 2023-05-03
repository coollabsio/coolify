<div>
    <div>
        <label for="command">
            <input autofocus id="command" wire:model.defer="command" type="text" wire:keydown.enter="runCommand" />
            <select wire:model.defer="server">
                @foreach ($servers as $server)
                    <option value="{{ $server->uuid }}">{{ $server->name }}</option>
                @endforeach
            </select>
        </label>
        <x-inputs.button wire:click="runCommand">Run command</x-inputs.button>
        <x-inputs.button wire:click="runSleepingBeauty">Run sleeping beauty</x-inputs.button>
        <x-inputs.button wire:click="runDummyProjectBuild">Build DummyProject</x-inputs.button>
    </div>

    <div>
        <input id="manualKeepAlive" name="manualKeepAlive" type="checkbox" wire:model="manualKeepAlive">
        <label for="manualKeepAlive">Real-time logs</label>
        @if ($isKeepAliveOn || $manualKeepAlive)
            Polling...
        @endif
    </div>
    @isset($activity?->id)
        <pre style="width: 100%;overflow-y: scroll;" @if ($isKeepAliveOn) wire:poll.750ms="polling" @endif>{{ \App\Actions\CoolifyTask\RunRemoteProcess::decodeOutput($activity) }}</pre>
    @endisset
</div>

<div>
    <div>
        <div>
            <label for="command">
                <input class="ring-1" id="command" wire:model="command" type="text"/>
            </label>
            <button class="btn btn-success btn-xs rounded-none" wire:click="runCommand">
                Run command
            <button>
        </div>

        @isset($activity?->id)
            <div>
                Activity: <span>{{ $activity?->id ?? 'waiting' }}</span>
            </div>
        @endisset
    </div>

    <div class="w-full h-10"></div>

    <pre
        style="
            background-color: #FFFFFF;
            width: 1200px;
            height: 600px;
            overflow-y: scroll;
            display: flex;
            flex-direction: column-reverse;
        "
        placeholder="Build output"
        @if($isKeepAliveOn || $manualKeepAlive) wire:poll.750ms="polling" @endif
    >
        {{ data_get($activity, 'description') }}
    </pre>

    <div>
        <input id="manualKeepAlive" name="manualKeepAlive" type="checkbox" wire:model="manualKeepAlive">
        <label for="manualKeepAlive"> Live content </label>
    </div>

    @if($isKeepAliveOn || $manualKeepAlive)
        Polling...
    @endif
</div>

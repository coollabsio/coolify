<div>
    <div>
        <div>
            <label for="command">
                <input class="py-2 rounded ring-1" id="command" wire:model="command" type="text"/>
            </label>
            <button
                @disabled($activity)
                class="bg-indigo-500 rounded py-2 px-4 disabled:bg-gray-300"
                wire:click="runCommand"
            >
                Run command
            <button>
        </div>

        <div class="mt-2 flex gap-2">
            <button
                @disabled($activity)
                class="bg-indigo-500 rounded py-2 px-4 disabled:bg-gray-300"
                wire:click="runSleepingBeauty"
            >
                Run sleeping beauty
            <button>
        </div>
        <div class="mt-2 flex gap-2">
            <button
                @disabled($activity)
                class="bg-indigo-500 rounded py-2 px-4 disabled:bg-gray-300"
                wire:click="runDummyProjectBuild"
            >
                Build DummyProject
            <button>
        </div>


        @isset($activity?->id)
            <div>
                Activity: <span>{{ $activity?->id ?? 'waiting' }}</span>
            </div>
        @endisset
    </div>

    <div class="w-full h-10"></div>


    <div
        @if($isKeepAliveOn || $manualKeepAlive) wire:poll.750ms="polling" @endif
    >
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

    <pre
        style="
            background-color: #FFFFFF;
            width: 1200px;
            height: 300px;
            overflow-y: scroll;
            display: flex;
            flex-direction: column-reverse;
        "
        placeholder="Build output"
    >{{ json_encode(data_get($activity, 'properties'), JSON_PRETTY_PRINT) }}</pre>
</div>

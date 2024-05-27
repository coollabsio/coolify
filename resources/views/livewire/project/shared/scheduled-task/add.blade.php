<form class="flex flex-col w-full gap-2 rounded" wire:submit='submit'>
    <x-forms.input autofocus placeholder="Run cron" id="name" label="Name" />
    <x-forms.input placeholder="php artisan schedule:run" id="command" label="Command" />
    <x-forms.input placeholder="0 0 * * * or daily" id="frequency" label="Frequency" />
    @if ($type === 'application')
        @if ($containerNames->count() > 1)
            <x-forms.select id="container" label="Container name">
                @foreach ($containerNames as $containerName)
                    <option value="{{ $containerName }}">{{ $containerName }}</option>
                @endforeach
            </x-forms.select>
        @else
            <x-forms.input placeholder="php" id="container"
                helper="You can leave it empty if your resource only have one container." label="Container name" />
        @endif
    @elseif ($type === 'service')
        <x-forms.select id="container" label="Container name">
            @foreach ($containerNames as $containerName)
                <option value="{{ $containerName }}">{{ $containerName }}</option>
            @endforeach
        </x-forms.select>
    @endif

    <x-forms.button @click="modalOpen=false" type="submit">
        Save
    </x-forms.button>
</form>

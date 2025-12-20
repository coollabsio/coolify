<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.input placeholder="Run cron" id="name" label="{{ __('scheduled_task.name_label') }}" />
    <x-forms.input placeholder="php artisan schedule:run" id="command" label="{{ __('scheduled_task.command_label') }}" />
    <x-forms.input placeholder="0 0 * * * or daily"
        helper="{{ __('scheduled_task.frequency_helper') }}" id="frequency"
        label="{{ __('scheduled_task.frequency_label') }}" />
    <x-forms.input type="number" placeholder="300" id="timeout"
        helper="{{ __('scheduled_task.timeout_helper') }}"
        label="{{ __('scheduled_task.timeout_label') }}" />
    @if ($type === 'application')
        @if ($containerNames->count() > 1)
            <x-forms.select id="container" label="{{ __('scheduled_task.container_label') }}">
                @foreach ($containerNames as $containerName)
                    <option value="{{ $containerName }}">{{ $containerName }}</option>
                @endforeach
            </x-forms.select>
        @else
            <x-forms.input placeholder="php" id="container"
                helper="{{ __('scheduled_task.container_helper') }}" label="{{ __('scheduled_task.container_label') }}" />
        @endif
    @elseif ($type === 'service')
        <x-forms.select id="container" label="{{ __('scheduled_task.container_label') }}">
            @foreach ($containerNames as $containerName)
                <option value="{{ $containerName }}">{{ $containerName }}</option>
            @endforeach
        </x-forms.select>
    @endif

    <x-forms.button @click="modalOpen=false" type="submit">
        {{ __('button.save') }}
    </x-forms.button>
</form>

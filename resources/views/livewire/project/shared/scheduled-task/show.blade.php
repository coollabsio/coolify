<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > {{ __('menu.scheduled_tasks') }} | Coolify
    </x-slot>
    @if ($type === 'application')
        <h1>{{ __('scheduled_task.title_singular') }}</h1>
        <livewire:project.application.heading :application="$resource" />
    @elseif ($type === 'service')
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" />
    @endif

    <form wire:submit="submit" class="w-full">
        <div class="flex flex-col gap-2 pb-2">
            <div class="flex gap-2 items-end">
                <h2>{{ __('scheduled_task.title_singular') }}</h2>
                <x-forms.button type="submit">
                    {{ __('button.save') }}
                </x-forms.button>
                @if ($resource->isRunning())
                    <x-forms.button type="button" wire:click="executeNow">
                        {{ __('scheduled_task.execute_now') }}
                    </x-forms.button>
                @endif
                <x-modal-confirmation title="{{ __('scheduled_task.confirm_delete_title') }}" isErrorButton buttonTitle="{{ __('button.delete') }}"
                    submitAction="delete({{ $task->id }})" :actions="[__('scheduled_task.delete_action')]" confirmationText="{{ $task->name }}"
                    confirmationLabel="{{ __('scheduled_task.confirm_delete_label') }}"
                    shortConfirmationLabel="{{ __('scheduled_task.task_name_label') }}" :confirmWithPassword="false"
                    step2ButtonText="{{ __('button.permanently_delete') }}" />

            </div>
            <div class="w-48">
                <x-forms.checkbox instantSave id="isEnabled" label="{{ __('scheduled_task.enabled') }}" />
            </div>
            <div class="flex gap-2 w-full">
                <x-forms.input placeholder="Name" id="name" label="{{ __('scheduled_task.name_label') }}" required />
                <x-forms.input placeholder="php artisan schedule:run" id="command" label="{{ __('scheduled_task.command_label') }}" required />
                <x-forms.input placeholder="0 0 * * * or daily" id="frequency" label="{{ __('scheduled_task.frequency_label') }}" required />
                <x-forms.input type="number" placeholder="300" id="timeout"
                    helper="{{ __('scheduled_task.timeout_helper') }}" label="{{ __('scheduled_task.timeout_label') }}" required />
                @if ($type === 'application')
                    <x-forms.input placeholder="php"
                        helper="{{ __('scheduled_task.container_helper') }}" id="container"
                        label="{{ __('scheduled_task.container_label') }}" />
                @elseif ($type === 'service')
                    <x-forms.input placeholder="php"
                        helper="{{ __('scheduled_task.service_name_helper') }}"
                        id="container" label="{{ __('scheduled_task.service_name_label') }}" />
                @endif
            </div>
    </form>

    <div class="pt-4">
        <h3 class="py-4">{{ __('scheduled_task.recent_executions') }} <span class="text-xs text-neutral-500">{{ __('scheduled_task.click_to_check') }}</span></h3>
        <livewire:project.shared.scheduled-task.executions :taskId="$task->id" />
    </div>
</div>

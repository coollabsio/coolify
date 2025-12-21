<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.input placeholder="{{ __('forms.placeholders.project_name') }}" id="name" label="Name" required />
    <x-forms.input placeholder="{{ __('forms.placeholders.project_description') }}" id="description" label="Description" />
    <div class="subtitle">{!! __('project.new_project_default_env') !!}</div>
    <x-forms.button type="submit">
        {{ __('common.continue') }}
    </x-forms.button>
</form>

<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > {{ __('project.edit') }} | Coolify
        </x-slot>
        <form wire:submit='submit' class="flex flex-col pb-10">
            <div class="flex gap-2">
                <h1>{{ data_get_str($project, 'name')->limit(15) }}</h1>
                <div class="flex items-end gap-2">
                    <x-forms.button type="submit">{{ __('button.save') }}</x-forms.button>
                    <livewire:project.delete-project :disabled="!$project->isEmpty()" :project_id="$project->id" />
                </div>
            </div>
            <div class="pt-2 pb-10">{{ __('project.edit_description') }}</div>
            <div class="flex gap-2">
                <x-forms.input label="{{ __('project.name') }}" id="name" />
                <x-forms.input label="{{ __('project.description') }}" id="description" />
            </div>
        </form>
</div>
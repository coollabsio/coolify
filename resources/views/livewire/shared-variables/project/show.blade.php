<div>
    <x-slot:title>
        Project Variables | Coolify
    </x-slot>

    <x-shared-variables.editor :resource="$project" :variables="$project->environment_variables"
        type="project" title="{{ $project->name }}" :view="$view" variablesLabel="Project shared variables" />
</div>

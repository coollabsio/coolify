<div>
    <x-slot:title>
        Environment Variables | Coolify
    </x-slot>

    <x-shared-variables.editor :resource="$environment"
        :variables="$this->editableVariables" type="environment"
        title="{{ $project->name }} / {{ $environment->name }}"
        :view="$view" variablesLabel="Environment shared variables"
        :inheritedVariables="$this->inheritedVariables" />
</div>

<div>
    <x-slot:title>
        Environment Variables | Coolify
    </x-slot>

    <x-shared-variables.editor :resource="$environment"
        :variables="$environment->environment_variables" type="environment"
        title="{{ $project->name }} / {{ $environment->name }}"
        :view="$view" variablesLabel="Environment shared variables" />
</div>

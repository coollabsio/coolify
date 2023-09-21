<div x-init="$wire.check_status">
    <livewire:project.service.modal />
    <h1>Configuration</h1>
    <x-resources.breadcrumbs :resource="$service" :parameters="$parameters" />
    <x-services.navbar :service="$service" :parameters="$parameters" />
    <h3>Applications</h3>
    @foreach ($service->applications as $application)
        <form class="box" wire:submit.prevent='submit'>
            <p>{{ $application->name }}</p>
            <x-forms.input id="services.{{ $application->name }}.fqdn"></x-forms.input>
            <x-forms.button type="submit">Save</x-forms.button>
        </form>
    @endforeach
    @if ($service->databases->count() > 0)
        <h3>Databases</h3>
    @endif
    @foreach ($service->databases as $database)
        <p>{{ $database->name }}</p>
        <p>{{ $database->status }}</p>
    @endforeach
    <h3>Variables</h3>
    @foreach ($service->environment_variables as $variable)
        <p>{{ $variable->key }}={{ $variable->value }}</p>
    @endforeach
</div>

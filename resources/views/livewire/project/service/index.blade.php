<div x-init="$wire.check_status">
    <h1>Configuration</h1>
    <x-resources.breadcrumbs :resource="$service" :parameters="$parameters" />
    <h3>Applications</h3>
    @foreach ($service->applications as $application)
    <form wire:submit.prevent='submit'>
        <p>{{ $application->name }}</p>
        <p>{{ $application->status }}</p>
        <x-forms.input id="services.{{$application->name}}.fqdn"></x-forms.input>
        <x-forms.button type="submit">Save</x-forms.button>
    </form>
    @endforeach
    <h3>Databases</h3>
    @foreach ($service->databases as $database)
        <p>{{ $database->name }}</p>
        <p>{{ $database->status }}</p>
    @endforeach
    <h3>Variables</h3>
    @foreach ($service->environment_variables as $variable)
        <p>{{ $variable->key }}={{ $variable->value }}</p>
    @endforeach
    <x-forms.button wire:click='deploy'>Deploy</x-forms.button>
    <div class="container w-full py-10 mx-auto">
        <livewire:activity-monitor header="Service Startup Logs" />
    </div>
</div>

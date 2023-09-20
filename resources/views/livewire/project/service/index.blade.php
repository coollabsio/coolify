<div>
    <h1>Configuration</h1>
    <h3>Applications</h3>
    @foreach ($service->applications as $application)
        <p>{{ $application->name }}</p>
    @endforeach
    <h3>Databases</h3>
    @foreach ($service->databases as $database)
        <p>{{ $database->name }}</p>
    @endforeach
    <h3>Variables</h3>
    @foreach ($service->environment_variables as $variable)
        <p>{{ $variable->key }}={{ $variable->value }}</p>
    @endforeach

</div>

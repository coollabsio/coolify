@if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
    <x-dashboard.navbar section="destination" :parameters="['destination_uuid' => $destination->uuid]" />
@endif

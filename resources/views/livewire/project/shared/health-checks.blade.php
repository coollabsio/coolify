<form wire:submit='submit' class="flex flex-col">
    <div class="flex items-center gap-2">
        <h2>Healthchecks</h2>
        <x-forms.button type="submit">Save</x-forms.button>
    </div>
    <div class="pb-4">Define how your resource's health should be checked.</div>
    <div class="flex flex-col gap-4">
        @if ($resource->custom_healthcheck_found)
            <div class="text-warning">A custom health check has been found and will be used until you enable this.</div>
        @endif
        <div class="w-32">
            <x-forms.checkbox instantSave id="resource.health_check_enabled" label="Enabled" />
        </div>
        <div class="flex gap-2">
            <x-forms.input id="resource.health_check_method" placeholder="GET" label="Method" required />
            <x-forms.input id="resource.health_check_scheme" placeholder="http" label="Scheme" required />
            <x-forms.input id="resource.health_check_host" placeholder="localhost" label="Host" required />
            <x-forms.input type="number" id="resource.health_check_port"
                helper="If no port is defined, the first exposed port will be used." placeholder="80" label="Port" />
            <x-forms.input id="resource.health_check_path" placeholder="/health" label="Path" required />
        </div>
        <div class="flex gap-2">
            <x-forms.input type="number" id="resource.health_check_return_code" placeholder="200" label="Return Code"
                required />
            <x-forms.input id="resource.health_check_response_text" placeholder="OK" label="Response Text" />
        </div>
        <div class="flex gap-2">
            <x-forms.input min=1 type="number" id="resource.health_check_interval" placeholder="30" label="Interval"
                required />
            <x-forms.input type="number" id="resource.health_check_timeout" placeholder="30" label="Timeout"
                required />
            <x-forms.input type="number" id="resource.health_check_retries" placeholder="3" label="Retries" required />
            <x-forms.input min=1 type="number" id="resource.health_check_start_period" placeholder="30"
                label="Start Period" required />
        </div>
    </div>
</form>

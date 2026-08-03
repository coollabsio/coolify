<div>
    <x-slot:title>
        Server Variables | Coolify
    </x-slot>

    <x-shared-variables.editor :resource="$server"
        :variables="$server->environment_variables->whereNotIn('key', ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'])"
        type="server" title="{{ $server->name }}"
        :view="$view" variablesLabel="Server shared variables" />
</div>

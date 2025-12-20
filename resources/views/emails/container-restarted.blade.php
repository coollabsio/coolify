<x-emails.layout>
{{ __('email.container_restarted.body', ['name' => $containerName, 'server_name' => $serverName]) }}

@if ($containerName === 'coolify-proxy')
{{ __('email.container_restarted.proxy_body') }}

{{ __('email.container_restarted.proxy_action') }}
@endif
</x-emails.layout>

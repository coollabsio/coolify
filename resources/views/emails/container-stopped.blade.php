<x-emails.layout>
{{ __('email.container_stopped.body', ['name' => $containerName, 'server_name' => $serverName]) }}

@if ($url)
{{ __('email.container_stopped.action', ['url' => $url]) }}
@endif
</x-emails.layout>

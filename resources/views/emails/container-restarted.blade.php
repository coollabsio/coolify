<x-emails.layout>

Container ({{ $containerName }}) has been restarted automatically on {{$serverName}}, because it was stopped unexpected.

@if ($containerName === 'coolify-proxy')
Coolify Proxy should run on your server as you have FQDN set up in one of your resources. If you don't want to use Coolify Proxy, please remove FQDN from your resources.

Note: The proxy should not stop unexpectedly, so please check what is going on your server.
@endif

</x-emails.layout>

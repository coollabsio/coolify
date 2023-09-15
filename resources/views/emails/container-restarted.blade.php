<x-emails.layout>

Container ({{ $containerName }}) has been restarted automatically on {{$serverName}}, because it was stopped unexpectedly.

@if ($containerName === 'coolify-proxy')
Coolify Proxy should run on your server as you have FQDNs set up in one of your resources.

Note: The proxy should not stop unexpectedly, so please check what is going on your server.



If you don't want to use Coolify Proxy, please remove FQDN from your resources or set Proxy type to Custom(None).
@endif

</x-emails.layout>

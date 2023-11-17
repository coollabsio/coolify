<x-emails.layout>

A service ({{ $containerName }}) has been stopped unexpectedly on {{$serverName}}.

@if ($url)
Please check what is going on [here]({{ $url }}).
@endif

</x-emails.layout>

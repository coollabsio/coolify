<x-emails.layout>

Container {{ $containerName }} has been stopped unexpectedly on {{$serverName}}.

@if ($url)
Please check what is going on [here]({{ $url }}).
@endif

</x-emails.layout>

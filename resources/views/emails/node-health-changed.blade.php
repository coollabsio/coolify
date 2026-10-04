<x-emails.layout>
{{ $description }}

@if (filled($details ?? null))
{{ $details }}

@endif
[Open in Coolify]({{ $url }})
</x-emails.layout>

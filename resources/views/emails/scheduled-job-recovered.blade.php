<x-emails.layout>
{{ $title }}

The last run succeeded after failed runs.

@if ($url)
Click [here]({{ $url }}) to open it in Coolify.
@endif
</x-emails.layout>

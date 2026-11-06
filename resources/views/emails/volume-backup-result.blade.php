<x-emails.layout>
{{ $summary }}

Frequency: {{ $frequency }}

@isset($output)
### Reason

{{ $output }}
@endisset

@isset($reminder)
{{ $reminder }}
@endisset

@if ($url)
Click [here]({{ $url }}) to open the resource.
@endif
</x-emails.layout>

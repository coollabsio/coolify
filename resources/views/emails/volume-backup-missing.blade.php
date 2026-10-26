<x-emails.layout>
{{ $summary }}

@if ($last_execution_at)
The last execution was at {{ $last_execution_at }}.
@else
This schedule has never produced an execution.
@endif

@if ($url)
Click [here]({{ $url }}) to open the resource.
@endif
</x-emails.layout>

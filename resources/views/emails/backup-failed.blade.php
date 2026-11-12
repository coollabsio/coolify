<x-emails.layout>
Database backup for {{ $name }} @if($database_name)(db:{{ $database_name }})@endif with frequency of {{ $frequency }} was FAILED.

### Reason

{{ $output }}

@isset($reminder)
{{ $reminder }}
@endisset
</x-emails.layout>

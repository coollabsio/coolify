<x-emails.layout>
Database restore for {{ $name }} was successful.
@if($label)
Restored from backup: {{ $label }}
@endif
@if($target_time)
Target time: {{ $target_time }}
@endif
</x-emails.layout>

<x-emails.layout>
Database restore for {{ $name }} has failed.
@if($label)
Attempted to restore from backup: {{ $label }}
@endif

Error: {{ $error }}
</x-emails.layout>

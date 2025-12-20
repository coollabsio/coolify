<x-emails.layout>
{{ __('email.backup.failed', ['name' => $name, 'db_name' => $database_name ? "(db:$database_name)" : "", 'frequency' => $frequency]) }}

### {{ __('email.backup.reason') }}

{{ $output }}
</x-emails.layout>

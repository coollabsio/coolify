<x-emails.layout>
{{ __('email.backup.success', ['name' => $name, 'db_name' => $database_name ? "(db:$database_name)" : "", 'frequency' => $frequency]) }}
</x-emails.layout>

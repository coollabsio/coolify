<x-emails.layout>
{{ __('email.backup_success_s3_failed.body', ['name' => $name, 'db_name' => $database_name ? "(db:$database_name)" : "", 'frequency' => $frequency]) }}

{{ __('email.backup_success_s3_failed.error', ['error' => $s3_error]) }}

@if($s3_storage_url)
{{ __('email.backup_success_s3_failed.check_config', ['url' => $s3_storage_url]) }}
@endif
</x-emails.layout>

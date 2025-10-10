<x-emails.layout>
Database backup for {{ $name }} @if($database_name)(db:{{ $database_name }})@endif with frequency of {{ $frequency }} succeeded locally but failed to upload to S3.

S3 Error: {{ $s3_error }}

@if($s3_storage_url)
Check S3 Configuration: {{ $s3_storage_url }}
@endif
</x-emails.layout>

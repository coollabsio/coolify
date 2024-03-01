<x-emails.layout>
@foreach ($databases as $database_name => $databases)

@if(data_get($databases,'failed_count') > 0)

<div style="color:red">

"{{ $database_name }}" backups: There were some failed backups. Please login and check the logs for more details.

</div>

@else

"{{ $database_name }}" backups: All backups were successful.

@endif

@endforeach
</x-emails.layout>

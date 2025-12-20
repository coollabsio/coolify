<x-emails.layout>
@foreach ($databases as $database_name => $databases)

@if(data_get($databases,'failed_count') > 0)

<div style="color:red">

{{ __('email.daily_backup.failed', ['name' => $database_name]) }}

</div>

@else

{{ __('email.daily_backup.success', ['name' => $database_name]) }}

@endif

@endforeach
</x-emails.layout>

<x-emails.layout>
{{ $name }} health status changed to **{{ $new_status }}**.

@if($new_status === 'unhealthy')
Your application is now unhealthy. [Check what is going on]({{ $application_url }}).
@else
Your application has recovered and is now healthy. [Open Application]({{ $application_url }}).
@endif
</x-emails.layout>

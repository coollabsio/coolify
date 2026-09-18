<x-emails.layout>
{{ $name }} has been automatically stopped after {{ $restart_count }} restarts (limit: {{ $max_restart_count }}).

The application appears to be in a restart loop. Please investigate the issue and redeploy when ready.

[Check what is going on]({{ $resource_url }}).
</x-emails.layout>

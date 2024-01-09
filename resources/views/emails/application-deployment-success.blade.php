<x-emails.layout>
@if ($pull_request_id === 0)
A new version of {{ $name }} is available at [{{ $fqdn }}]({{ $fqdn }}) .
@else
Pull request #{{ $pull_request_id }} of {{ $name }} deployed successfully
[{{ $fqdn }}]({{ $fqdn }}).
@endif

[View Deployment Logs]({{ $deployment_url }})

</x-emails.layout>

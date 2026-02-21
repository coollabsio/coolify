<x-emails.layout>
@if ($pull_request_id === 0)
A new version of {{ $name }} is available at [{{ $fqdn }}]({{ $fqdn }}) .
@else
Pull request #{{ $pull_request_id }} of {{ $name }} deployed successfully
[{{ $fqdn }}]({{ $fqdn }}).
@endif

@if (isset($commit_author))
Commit Author: {{ $commit_author }}
@endif

[View Deployment Logs]({{ $deployment_url }})

</x-emails.layout>

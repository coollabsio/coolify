<x-emails.layout>
@if ($pull_request_id === 0)
Failed to deploy a new version of {{ $name }} at [{{ $fqdn }}]({{ $fqdn }}) .
@else
Failed to deploy a pull request #{{ $pull_request_id }} of {{ $name }} at
[{{ $fqdn }}]({{ $fqdn }}).
@endif

<x-emails.deployment-commit :commit="$commit ?? null" />
[View Deployment Logs]({{ $deployment_url }})
</x-emails.layout>

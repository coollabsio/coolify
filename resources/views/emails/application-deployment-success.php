@if ($pull_request_id === 0)
    A new version of <a target="_blank" href="{{ $fqdn }}">{{ $fqdn }}</a> is available:
@else
    Pull request #{{ $pull_request_id }} of {{ $name }} deployed successfully: <a target="_blank"
        href="{{ $fqdn }}">Application Link</a> |
@endif
<a target="_blank" href="{{ $deployment_url }}">View
    Deployment Logs</a><br><br>

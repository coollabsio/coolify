@if ($pull_request_id !== 0)
    Pull Request #{{ $pull_request_id }} of {{ $name }} (<a target="_blank"
        href="{{ $fqdn }}">{{ $fqdn }}</a>) deployment failed:
@else
    Deployment failed of {{ $name }} (<a target="_blank" href="{{ $fqdn }}">{{ $fqdn }}</a>):
@endif

<a target="_blank" href="{{ $deployment_url }}">View Deployment Logs</a><br><br>

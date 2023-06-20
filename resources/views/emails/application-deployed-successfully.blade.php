@if ($pull_request_id === 0)
    A new version of <a target="_blank" href="{{ $fqdn }}">{{ $fqdn }}</a> is available.<br><br>
@else
    Pull request #{{ $pull_request_id }} is available for review at <a target="_blank"
        href="{{ $fqdn }}">{{ $fqdn }}</a><br><br>
@endif
<a target="_blank" href="{{ $url }}">View
    Deployment Logs</a><br><br>

Hello,<br><br>

Deployment failed of "{{ $name }}"

@if ($pull_request_id !== 0)
    :PR #{{ $pull_request_id }}
@endif

to <a target="_blank" href="{{ $fqdn }}">{{ $fqdn }}</a>.<br><br>

Click the following link to view the deployment logs: <a target="_blank" href="{{ $url }}">View
    Deployment Logs</a><br><br>

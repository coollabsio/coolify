Hello,<br><br>

A new version of your application "{{ $name }}"@if ($pull_request_id !== 0)
    :PR#{{ $pull_request_id }}
@endif has been deployed to <a target="_blank"
    href="{{ $fqdn }}">{{ $fqdn }}</a><br><br>

Click the following link to view the deployment logs: <a target="_blank" href="{{ $url }}">View
    Deployment</a><br><br>

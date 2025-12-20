<x-emails.layout>
@if ($pull_request_id === 0)
{{ __('email.application.deployment_success', ['name' => $name, 'url' => $fqdn]) }}
@else
{{ __('email.application.deployment_success_pr', ['pr_id' => $pull_request_id, 'name' => $name, 'url' => $fqdn]) }}
@endif

[{{ __('email.application.view_deployment_logs') }}]({{ $deployment_url }})

</x-emails.layout>

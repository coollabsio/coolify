<x-emails.layout>
{{ __('email.application_status_changed.body', ['name' => $name]) }}

{{ __('email.application_status_changed.explanation') }}

{{ __('email.application_status_changed.action', ['url' => $application_url]) }}
</x-emails.layout>

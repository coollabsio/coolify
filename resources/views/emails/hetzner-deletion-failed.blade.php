<x-emails.layout>
{{ __('email.hetzner_deletion_failed.body', ['id' => $hetznerServerId]) }}

{{ __('email.hetzner_deletion_failed.error') }}
<pre>
{{ $errorMessage }}
</pre>

{{ __('email.hetzner_deletion_failed.explanation') }}

{{ __('email.hetzner_deletion_failed.action') }}

</x-emails.layout>

<x-emails.layout>
{{ __('email.reset_password.body') }}

{{ __('email.reset_password.action', ['url' => $url]) }}

{{ __('email.reset_password.expiry', ['count' => $count]) }}
</x-emails.layout>

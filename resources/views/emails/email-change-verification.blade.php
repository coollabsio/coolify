<x-emails.layout>
{{ __('email.email_change_verification.body', ['email' => $newEmail]) }}

{{ __('email.email_change_verification.code') }}

{{ __('email.email_change_verification.code_text', ['code' => $verificationCode]) }}

{{ __('email.email_change_verification.expiry', ['minutes' => $expiryMinutes]) }}

{{ __('email.email_change_verification.ignore') }}
</x-emails.layout>
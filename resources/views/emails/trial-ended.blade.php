<x-emails.layout>
{{ __('email.trial_ended.body') }}

{{ __('email.trial_ended.action', ['url' => $stripeCustomerPortal]) }}
</x-emails.layout>

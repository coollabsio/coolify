<x-emails.layout>
{{ __('email.subscription_invoice_failed.body') }}

{{ __('email.subscription_invoice_failed.action', ['url' => $stripeCustomerPortal]) }}
</x-emails.layout>

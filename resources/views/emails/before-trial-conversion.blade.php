<x-emails.layout>
{{ __('email.before_trial_conversion.body', ['days' => config('constants.limits.trial_period')]) }}

{{ __('email.before_trial_conversion.explanation') }}

{{ __('email.before_trial_conversion.action', ['url' => 'https://app.coolify.io/subscription/new']) }}
</x-emails.layout>

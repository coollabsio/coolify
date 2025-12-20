<x-emails.layout>
    {{ __('email.server_force_disabled.body', ['name' => $name]) }}

    {{ __('email.server_force_disabled.action', ['url' => 'https://app.coolify.io/subscriptions']) }}
</x-emails.layout>

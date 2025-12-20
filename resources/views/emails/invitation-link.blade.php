<x-emails.layout>
{{ __('email.invitation.body', ['team' => $team, 'app_name' => config('app.name')]) }}

{{ __('email.invitation.accept_link', ['url' => $invitation_link]) }}

{{ __('email.invitation.contact_owner') }}<br><br>

{{ __('email.invitation.ignore') }}
</x-emails.layout>

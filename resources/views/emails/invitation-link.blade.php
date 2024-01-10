<x-emails.layout>
You have been invited to "{{ $team }}" on "{{ config('app.name') }}".

Please [click here]({{ $invitation_link }}) to accept the invitation.

If you have any questions, please contact the team owner.<br><br>

If it was not you who requested this invitation, please ignore this email.
</x-emails.layout>

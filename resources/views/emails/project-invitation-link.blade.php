<x-emails.layout>
You have been invited to project "{{ $project }}" in team "{{ $team }}" on "{{ config('app.name') }}".

Your role: {{ $role }}

Please [click here]({{ $invitation_link }}) to accept the invitation.

**Note:** As a project member, you will only have access to this specific project. You will not have access to other projects, servers, or SSH keys.

If you have any questions, please contact the team owner.<br><br>

If it was not you who requested this invitation, please ignore this email.
</x-emails.layout>

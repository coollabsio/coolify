<x-emails.layout>
You have been invited to join the project "{{ $project }}" in team "{{ $team }}" on "{{ config('app.name') }}".

Your role: {{ ucfirst($role) }}

Please [click here]({{ $login_link }}) to access the project.

If you have any questions, please contact the team owner.<br><br>

If it was not you who requested this invitation, please ignore this email.
</x-emails.layout>

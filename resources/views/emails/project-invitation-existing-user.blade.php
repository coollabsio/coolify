<x-emails.layout>
You have been added to the project "{{ $project }}" in team "{{ $team }}" on "{{ config('app.name') }}".

Your role: {{ ucfirst($role) }}

You can access the project by [clicking here]({{ $app_url }}/project/{{ $project_uuid }}).

If you have any questions, please contact the team owner.<br><br>

If you believe this was a mistake, please contact the team owner.
</x-emails.layout>

<x-emails.layout>
Your Coolify API token ({{ $tokenName }}) expires on {{ $expiresAt }}.

Rotate this token before it expires. API calls using this token will start failing once the expiration time is reached.

Manage your API tokens [here]({{ $manageUrl }}).
</x-emails.layout>

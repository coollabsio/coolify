<x-emails.layout>
A password reset has been requested for this email address on [{{ config('app.name') }}]({{ config('app.url') }}).

Click [here]({{ $url }}) to reset your password.

This link will expire in {{ $count }} minutes.
</x-emails.layout>

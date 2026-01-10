<x-emails.layout>
You have requested to change your email address to: {{ $newEmail }}

Please use the following verification code to confirm this change:

Verification Code: {{ $verificationCode }}

This code is valid for {{ $expiryMinutes }} minutes.

If you did not request this change, please ignore this email and your email address will remain unchanged.
</x-emails.layout>
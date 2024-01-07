<x-emails.layout>
Someone added this email to the Coolify Cloud's waitlist. [Click here]({{ $confirmation_url }}) to confirm!

The link will expire in {{ config('constants.waitlist.expiration') }} minutes.

You have no idea what [Coolify Cloud](https://coolify.io) is or this waitlist? [Click here]({{ $cancel_url }}) to remove you from the waitlist.
</x-emails.layout>

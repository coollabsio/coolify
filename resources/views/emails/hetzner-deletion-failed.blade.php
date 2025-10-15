<x-emails.layout>
Failed to delete Hetzner server #{{ $hetznerServerId }} from Hetzner Cloud.

Error:
<pre>
{{ $errorMessage }}
</pre>

The server has been removed from Coolify, but may still exist in your Hetzner Cloud account.

Please check your Hetzner Cloud console and manually delete the server if needed to avoid ongoing charges.

</x-emails.layout>

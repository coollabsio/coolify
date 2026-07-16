<x-emails.layout>
Failed to delete OpenStack server {{ $openstackServerId }}.

Error:
<pre>
{{ $errorMessage }}
</pre>

The server has been removed from Coolify, but may still exist in your OpenStack project.

Please check your OpenStack dashboard and manually delete the instance (and any associated floating IP) if needed to avoid ongoing charges.

</x-emails.layout>

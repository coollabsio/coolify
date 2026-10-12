<x-emails.layout>
Coolify did not update to {{ $version }} because the server does not have enough free disk space ({{ $available_gb }} GB free, {{ $required_gb }} GB required).

Free up disk space, for example with a [Docker cleanup]({{ $cleanup_url }}). The next automatic update will try again.
</x-emails.layout>

<x-emails.layout>
@if ($failed)
The automatic security updates on your server {{ $name }} failed.
@else
The automatic security updates on your server {{ $name }} installed {{ count($packages) }} package updates.
@endif

## Summary

- Run started: {{ $started_at }} (server time)
@if ($reboot_required)
- A reboot is required to apply the installed updates.
@endif

@if ($packages !== [])
## {{ $failed ? 'Packages to update' : 'Updated packages' }}

@foreach ($packages as $package)
- {{ $package }}
@endforeach
@endif

@if ($error_lines !== [])
## Errors

@foreach ($error_lines as $error)
- {{ $error }}
@endforeach
@endif

[Manage Server Patches]({{ $server_url }})
</x-emails.layout>

<x-emails.layout>
{{ $total_updates }} package updates are available for your server {{ $name }}.

## Summary

- Operating System: {{ ucfirst($osId) }}
- Package Manager: {{ $package_manager }}
- Total Updates: {{ $total_updates }}
@if (isset($security_updates))
- Security Updates: {{ $security_updates }}
@endif
@if ($reboot_required ?? false)
- A reboot is required to apply installed updates.
@endif

## Available Updates

@if ($total_updates > 0)
@foreach ($updates as $update)

Package: {{ $update['package'] }}{{ ($update['security'] ?? false) ? ' [security]' : '' }} ({{ $update['architecture'] ?? 'unknown' }}), from version {{ $update['current_version'] }} to {{ $update['new_version'] }} at repository {{ $update['repository'] ?? 'Unknown' }}
@endforeach

## Security Considerations

Some of these updates may include important security patches. We recommend reviewing and applying these updates promptly.

### Critical packages that may require container/server/service restarts:
@php
$criticalPackages = collect($updates)->filter(fn ($update) => filled($update['risk'] ?? null));
@endphp

@if ($criticalPackages->count() > 0)
@foreach ($criticalPackages as $package)
- {{ $package['package'] }}: {{ $package['current_version'] }} → {{ $package['new_version'] }}
@endforeach
@else
No critical packages requiring container restarts detected.
@endif

## Next Steps

1. Review the available updates
2. Plan maintenance window if critical packages are involved
3. Apply updates through the Coolify dashboard
4. Monitor services after updates are applied
@else
Your server is up to date! No packages require updating at this time.
@endif

---

You can manage server patches in your [Coolify Dashboard]({{ $server_url }}).
</x-emails.layout>

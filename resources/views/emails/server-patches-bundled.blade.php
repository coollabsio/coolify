<x-emails.layout>
{{ $count }} server(s) have package updates available. We recommend reviewing and applying these updates promptly.

## Affected Servers

@foreach ($servers as $server)
@php
    $serverName = data_get($server, 'name', 'Unknown Server');
    $serverUrl = data_get($server, 'url', '#');
    $patchData = data_get($server, 'patchData', []);
    $hasError = isset($patchData['error']);
    $totalUpdates = data_get($patchData, 'total_updates', 0);
    $updates = data_get($patchData, 'updates', []);
    $osId = data_get($patchData, 'osId', 'unknown');

    $criticalPackages = collect($updates)->filter(function ($update) {
        return str_contains(strtolower($update['package']), 'docker') ||
            str_contains(strtolower($update['package']), 'kernel') ||
            str_contains(strtolower($update['package']), 'openssh') ||
            str_contains(strtolower($update['package']), 'ssl');
    });
@endphp
@if ($hasError)
- [**{{ $serverName }}**]({{ $serverUrl }}): Failed to check updates ({{ ucfirst($osId) }}) — {{ $patchData['error'] }}
@elseif ($criticalPackages->count() > 0)
- [**{{ $serverName }}**]({{ $serverUrl }}): {{ $totalUpdates }} updates available ({{ ucfirst($osId) }}) | {{ $criticalPackages->count() }} critical package(s) may require restarts
@else
- [**{{ $serverName }}**]({{ $serverUrl }}): {{ $totalUpdates }} updates available ({{ ucfirst($osId) }})
@endif
@endforeach

## Security Considerations

Some of these updates may include important security patches.

@php
$allCritical = collect($servers)->flatMap(function ($server) {
    $updates = data_get(data_get($server, 'patchData', []), 'updates', []);
    $serverName = data_get($server, 'name', 'Unknown');
    return collect($updates)->filter(function ($update) {
        return str_contains(strtolower($update['package']), 'docker') ||
            str_contains(strtolower($update['package']), 'kernel') ||
            str_contains(strtolower($update['package']), 'openssh') ||
            str_contains(strtolower($update['package']), 'ssl');
    })->map(fn ($u) => array_merge($u, ['server' => $serverName]));
});
@endphp

### Critical packages that may require container/server/service restarts:

@if ($allCritical->count() > 0)
@foreach ($allCritical as $package)
- {{ $package['server'] }} — {{ $package['package'] }}: {{ $package['current_version'] }} → {{ $package['new_version'] }}
@endforeach
@else
No critical packages requiring container restarts detected.
@endif

## Next Steps

1. Review the available updates for each server
2. Plan maintenance windows if critical packages are involved
3. Apply updates through the Coolify dashboard
4. Monitor services after updates are applied

---

Click on any server name above to manage its patches.
</x-emails.layout>

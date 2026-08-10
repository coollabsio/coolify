<x-emails.layout>
{{ $count }} server(s) are running outdated Traefik proxy. Update recommended for security and features.

## Affected Servers

@foreach ($servers as $server)
@php
    $serverName = data_get($server, 'name', 'Unknown Server');
    $serverUrl = data_get($server, 'url', '#');
    $info = data_get($server, 'outdatedInfo', []);
    $current = data_get($info, 'current', 'unknown');
    $latest = data_get($info, 'latest', 'unknown');
    $isPatch = (data_get($info, 'type', 'patch_update') === 'patch_update');
    $hasNewerBranch = isset($info['newer_branch_target']);
    $hasUpgrades = $hasUpgrades ?? false;
    if (!$isPatch || $hasNewerBranch) {
        $hasUpgrades = true;
    }
    // Add 'v' prefix for display
    $current = str_starts_with($current, 'v') ? $current : "v{$current}";
    $latest = str_starts_with($latest, 'v') ? $latest : "v{$latest}";

    // For minor upgrades, use the upgrade_target (e.g., "v3.6")
    if (!$isPatch && data_get($info, 'upgrade_target')) {
        $upgradeTarget = data_get($info, 'upgrade_target');
        $upgradeTarget = str_starts_with($upgradeTarget, 'v') ? $upgradeTarget : "v{$upgradeTarget}";
    } else {
        // For patch updates, show the full version
        $upgradeTarget = $latest;
    }

    // Get newer branch info if available
    if ($hasNewerBranch) {
        $newerBranchTarget = data_get($info, 'newer_branch_target', 'unknown');
        $newerBranchLatest = data_get($info, 'newer_branch_latest', 'unknown');
        $newerBranchLatest = str_starts_with($newerBranchLatest, 'v') ? $newerBranchLatest : "v{$newerBranchLatest}";
    }
@endphp
@if ($isPatch && $hasNewerBranch)
- [**{{ $serverName }}**]({{ $serverUrl }}): {{ $current }} → {{ $upgradeTarget }} (patch update available) | Also available: {{ $newerBranchTarget }} (latest patch: {{ $newerBranchLatest }}) - new minor version
@elseif ($isPatch)
- [**{{ $serverName }}**]({{ $serverUrl }}): {{ $current }} → {{ $upgradeTarget }} (patch update available)
@else
- [**{{ $serverName }}**]({{ $serverUrl }}): {{ $current }} (latest patch: {{ $latest }}) → {{ $upgradeTarget }} (new minor version available)
@endif
@endforeach

## Recommendation

It is recommended to test the new Traefik version before switching it in production environments. You can update your proxy configuration by clicking on any server name above.

@if ($hasUpgrades ?? false)
**Important for minor version upgrades:** Before upgrading to a new minor version, please read the [Traefik changelog](https://github.com/traefik/traefik/releases) to understand breaking changes and new features.
@endif

## Next Steps

1. Review the [Traefik release notes](https://github.com/traefik/traefik/releases) for changes
2. Test the new version in a non-production environment
3. Update your proxy configuration when ready
4. Monitor services after the update

---

Click on any server name above to manage its proxy settings.
</x-emails.layout>

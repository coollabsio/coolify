<x-emails.layout>
{{ $count }} server(s) are running outdated Traefik proxy. Update recommended for security and features.

## Affected Servers

@foreach ($servers as $server)
@php
    $info = $server->outdatedInfo ?? [];
    $current = $info['current'] ?? 'unknown';
    $latest = $info['latest'] ?? 'unknown';
    $isPatch = ($info['type'] ?? 'patch_update') === 'patch_update';
    $hasNewerBranch = isset($info['newer_branch_target']);
    $hasUpgrades = $hasUpgrades ?? false;
    if (!$isPatch || $hasNewerBranch) {
        $hasUpgrades = true;
    }
    // Add 'v' prefix for display
    $current = str_starts_with($current, 'v') ? $current : "v{$current}";
    $latest = str_starts_with($latest, 'v') ? $latest : "v{$latest}";

    // For minor upgrades, use the upgrade_target (e.g., "v3.6")
    if (!$isPatch && isset($info['upgrade_target'])) {
        $upgradeTarget = str_starts_with($info['upgrade_target'], 'v') ? $info['upgrade_target'] : "v{$info['upgrade_target']}";
    } else {
        // For patch updates, show the full version
        $upgradeTarget = $latest;
    }

    // Get newer branch info if available
    if ($hasNewerBranch) {
        $newerBranchTarget = $info['newer_branch_target'];
        $newerBranchLatest = str_starts_with($info['newer_branch_latest'], 'v') ? $info['newer_branch_latest'] : "v{$info['newer_branch_latest']}";
    }
@endphp
@if ($isPatch && $hasNewerBranch)
- **{{ $server->name }}**: {{ $current }} → {{ $upgradeTarget }} (patch update available) | Also available: {{ $newerBranchTarget }} (latest patch: {{ $newerBranchLatest }}) - new minor version
@elseif ($isPatch)
- **{{ $server->name }}**: {{ $current }} → {{ $upgradeTarget }} (patch update available)
@else
- **{{ $server->name }}**: {{ $current }} (latest patch: {{ $latest }}) → {{ $upgradeTarget }} (new minor version available)
@endif
@endforeach

## Recommendation

It is recommended to test the new Traefik version before switching it in production environments. You can update your proxy configuration through your [Coolify Dashboard]({{ config('app.url') }}).

@if ($hasUpgrades ?? false)
**Important for minor version upgrades:** Before upgrading to a new minor version, please read the [Traefik changelog](https://github.com/traefik/traefik/releases) to understand breaking changes and new features.
@endif

## Next Steps

1. Review the [Traefik release notes](https://github.com/traefik/traefik/releases) for changes
2. Test the new version in a non-production environment
3. Update your proxy configuration when ready
4. Monitor services after the update

---

You can manage your server proxy settings in your Coolify Dashboard.
</x-emails.layout>

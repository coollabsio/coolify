<x-emails.layout>
{{ __('email.traefik_outdated.body', ['count' => $count]) }}

## {{ __('email.traefik_outdated.affected_servers') }}

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
{{ __('email.traefik_outdated.patch_available_with_newer', ['name' => $serverName, 'url' => $serverUrl, 'current' => $current, 'target' => $upgradeTarget, 'newer_target' => $newerBranchTarget, 'newer_latest' => $newerBranchLatest]) }}
@elseif ($isPatch)
{{ __('email.traefik_outdated.patch_available', ['name' => $serverName, 'url' => $serverUrl, 'current' => $current, 'target' => $upgradeTarget]) }}
@else
{{ __('email.traefik_outdated.minor_available', ['name' => $serverName, 'url' => $serverUrl, 'current' => $current, 'latest' => $latest, 'target' => $upgradeTarget]) }}
@endif
@endforeach

## {{ __('email.traefik_outdated.recommendation') }}

{{ __('email.traefik_outdated.recommendation_body') }}

@if ($hasUpgrades ?? false)
**{{ __('email.traefik_outdated.important') }}** {{ __('email.traefik_outdated.important_body') }}
@endif

## {{ __('email.traefik_outdated.next_steps') }}

{{ __('email.traefik_outdated.next_steps_list') }}

---

{{ __('email.traefik_outdated.action') }}
</x-emails.layout>

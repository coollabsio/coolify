<x-emails.layout>
{{ $count }} server(s) are running outdated Traefik proxy. Update recommended for security and features.

**Note:** This check is based on the actual running container version, not the configuration file.

## Affected Servers

@foreach ($servers as $server)
@php
    $info = $server->outdatedInfo ?? [];
    $current = $info['current'] ?? 'unknown';
    $latest = $info['latest'] ?? 'unknown';
    $type = ($info['type'] ?? 'patch_update') === 'patch_update' ? '(patch)' : '(upgrade)';
    $hasUpgrades = $hasUpgrades ?? false;
    if ($type === 'upgrade') {
        $hasUpgrades = true;
    }
    // Add 'v' prefix for display
    $current = str_starts_with($current, 'v') ? $current : "v{$current}";
    $latest = str_starts_with($latest, 'v') ? $latest : "v{$latest}";
@endphp
- **{{ $server->name }}**: {{ $current }} → {{ $latest }} {{ $type }}
@endforeach

## Recommendation

It is recommended to test the new Traefik version before switching it in production environments. You can update your proxy configuration through your [Coolify Dashboard]({{ config('app.url') }}).

@if ($hasUpgrades ?? false)
**Important for major/minor upgrades:** Before upgrading to a new major or minor version, please read the [Traefik changelog](https://github.com/traefik/traefik/releases) to understand breaking changes and new features.
@endif

## Next Steps

1. Review the [Traefik release notes](https://github.com/traefik/traefik/releases) for changes
2. Test the new version in a non-production environment
3. Update your proxy configuration when ready
4. Monitor services after the update

---

You can manage your server proxy settings in your Coolify Dashboard.
</x-emails.layout>

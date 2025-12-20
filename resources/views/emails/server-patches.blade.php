<x-emails.layout>
{{ __('email.server_patches.body', ['count' => $total_updates, 'name' => $name]) }}

## {{ __('email.server_patches.summary') }}

- {{ __('email.server_patches.os', ['os' => ucfirst($osId)]) }}
- {{ __('email.server_patches.package_manager', ['manager' => $package_manager]) }}
- {{ __('email.server_patches.total_updates', ['count' => $total_updates]) }}

## {{ __('email.server_patches.available_updates') }}

@if ($total_updates > 0)
@foreach ($updates as $update)

{{ __('email.server_patches.package_details', ['package' => $update['package'], 'arch' => $update['architecture'], 'current' => $update['current_version'], 'new' => $update['new_version'], 'repo' => $update['repository'] ?? 'Unknown']) }}
@endforeach

## {{ __('email.server_patches.security_considerations') }}

{{ __('email.server_patches.security_body') }}

### {{ __('email.server_patches.critical_packages') }}
@php
$criticalPackages = collect($updates)->filter(function ($update) {
                return str_contains(strtolower($update['package']), 'docker') ||
                    str_contains(strtolower($update['package']), 'kernel') ||
                    str_contains(strtolower($update['package']), 'openssh') ||
                    str_contains(strtolower($update['package']), 'ssl');
            });
@endphp

@if ($criticalPackages->count() > 0)
@foreach ($criticalPackages as $package)
- {{ $package['package'] }}: {{ $package['current_version'] }} → {{ $package['new_version'] }}
@endforeach
@else
{{ __('email.server_patches.no_critical_packages') }}
@endif

## {{ __('email.server_patches.next_steps') }}

{{ __('email.server_patches.next_steps_list') }}
@else
{{ __('email.server_patches.up_to_date') }}
@endif

---

{{ __('email.server_patches.action', ['url' => $server_url]) }}
</x-emails.layout>

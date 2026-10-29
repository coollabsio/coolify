@php
    $securityMenuItems = collect([
        [
            'label' => 'Private Keys',
            'route' => 'security.private-key.index',
            'active' => request()->routeIs('security.private-key.*'),
            'icon' => 'keys',
        ],
        auth()->user()?->can('viewAny', App\Models\CloudProviderToken::class) ? [
            'label' => 'Cloud Tokens',
            'route' => 'security.cloud-tokens',
            'active' => request()->routeIs('security.cloud-tokens*'),
            'icon' => 'cloud',
        ] : null,
        auth()->user()?->can('viewAny', App\Models\IntegrationToken::class) ? [
            'label' => 'Integration Tokens',
            'route' => 'security.integration-tokens',
            'active' => request()->routeIs('security.integration-tokens'),
            'icon' => 'network',
        ] : null,
        auth()->user()?->can('viewAny', App\Models\CloudInitScript::class) ? [
            'label' => 'Cloud-Init Scripts',
            'route' => 'security.cloud-init-scripts',
            'active' => request()->routeIs('security.cloud-init-scripts*'),
            'icon' => 'file-content',
        ] : null,
        [
            'label' => 'API Tokens',
            'route' => 'security.api-tokens',
            'active' => request()->routeIs('security.api-tokens'),
            'icon' => 'code',
        ],
    ])->filter();
@endphp

{{-- These pages are in the Keys & Tokens submenu of the main sidebar (components/navbar.blade.php). --}}
<section class="w-full max-w-none">
    <header class="settings-mobile-header xl:hidden">
        <h1 class="settings-mobile-title">Keys & Tokens</h1>
        <p class="settings-mobile-description">Manage SSH keys, cloud credentials, and API access tokens.</p>
    </header>
    {{-- Feeds the page search with the Keys & Tokens pages. --}}
    <div hidden data-settings-search-items="{{ json_encode(settingsSearchItems(['Keys & Tokens' => $securityMenuItems], [])) }}"></div>
    <div class="min-w-0">
        @isset($actions)
            <div class="mb-3 flex min-h-8 flex-wrap items-center justify-end gap-2">
                {{ $actions }}
            </div>
        @endisset
        {{ $slot }}
    </div>
</section>

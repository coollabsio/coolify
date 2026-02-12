<div class="pb-6">
    <h1>Security</h1>
    <div class="subtitle">Security related settings.</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 scrollbar min-h-10">
            <a class="nav-tab {{ request()->routeIs('security.private-key.index') ? 'nav-tab-active' : '' }}" href="{{ route('security.private-key.index') }}" {{ wireNavigate() }}>
                Private Keys
            </a>
            @can('viewAny', App\Models\CloudProviderToken::class)
                <a class="nav-tab {{ request()->routeIs('security.cloud-tokens') ? 'nav-tab-active' : '' }}" href="{{ route('security.cloud-tokens') }}" {{ wireNavigate() }}>
                    Cloud Tokens
                </a>
            @endcan
            @can('viewAny', App\Models\CloudInitScript::class)
                <a class="nav-tab {{ request()->routeIs('security.cloud-init-scripts') ? 'nav-tab-active' : '' }}" href="{{ route('security.cloud-init-scripts') }}" {{ wireNavigate() }}>
                    Cloud-Init Scripts
                </a>
            @endcan
            <a class="nav-tab {{ request()->routeIs('security.api-tokens') ? 'nav-tab-active' : '' }}" href="{{ route('security.api-tokens') }}" {{ wireNavigate() }}>
                API Tokens
            </a>
        </nav>
    </div>
</div>

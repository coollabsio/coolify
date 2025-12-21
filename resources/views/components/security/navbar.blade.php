<div class="pb-6">
    <h1>{{ __('menu.security') }}</h1>
    <div class="subtitle">{{ __('shared.security_related_settings') }}</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 scrollbar min-h-10">
            <a href="{{ route('security.private-key.index') }}" {{ wireNavigate() }}>
                <button>{{ __('menu.private_key') }}</button>
            </a>
            @can('viewAny', App\Models\CloudProviderToken::class)
                <a href="{{ route('security.cloud-tokens') }}" {{ wireNavigate() }}>
                    <button>{{ __('security.cloud_tokens') }}</button>
                </a>
            @endcan
            @can('viewAny', App\Models\CloudInitScript::class)
                <a href="{{ route('security.cloud-init-scripts') }}" {{ wireNavigate() }}>
                    <button>{{ __('security.cloud_init_scripts') }}</button>
                </a>
            @endcan
            <a href="{{ route('security.api-tokens') }}" {{ wireNavigate() }}>
                <button>{{ __('security.api_tokens') }}</button>
            </a>
        </nav>
    </div>
</div>

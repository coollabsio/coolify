<div class="pb-6">
    <h1>Security</h1>
    <div class="subtitle">Security related settings.</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 scrollbar min-h-10">
            <a href="{{ route('security.private-key.index') }}">
                <button>Private Keys</button>
            </a>
            @can('viewAny', App\Models\CloudProviderToken::class)
                <a href="{{ route('security.cloud-tokens') }}">
                    <button>Cloud Tokens</button>
                </a>
            @endcan
            @can('viewAny', App\Models\CloudInitScript::class)
                <a href="{{ route('security.cloud-init-scripts') }}">
                    <button>Cloud-Init Scripts</button>
                </a>
            @endcan
            <a href="{{ route('security.api-tokens') }}">
                <button>API Tokens</button>
            </a>
        </nav>
    </div>
</div>

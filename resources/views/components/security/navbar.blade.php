<div class="pb-6">
    <h1>Security</h1>
    <div class="subtitle">Security related settings.</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 scrollbar min-h-10">
            <a wire:navigate href="{{ route('security.private-key.index') }}">
                <button>Private Keys</button>
            </a>
            <a wire:navigate href="{{ route('security.api-tokens') }}">
                <button>API tokens</button>
            </a>
        </nav>
    </div>
</div>

<div class="pb-6">
    <h1>Security</h1>
    <nav class="flex pt-2 pb-10">
        <ol class="inline-flex items-center">
            <li>
                <div class="flex items-center">
                    <span>Security related settings</span>
                </div>
            </li>
        </ol>
    </nav>
    <nav class="navbar-main">
        <a class="{{ request()->routeIs('security.private-key.index') ? 'text-white' : '' }}" href="{{ route('security.private-key.index') }}">
            <button>Private Keys</button>
        </a>
    </nav>
</div>

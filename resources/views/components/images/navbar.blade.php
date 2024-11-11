<div class="pb-6">
    <h1>Images</h1>
    <div class="subtitle">Images and container management.</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 scrollbar min-h-10">
            <a href="{{ route('images.images.index') }}"
                class="{{ request()->routeIs('images.images.index') ? 'dark:text-white ' : '' }}">
                <button>Images</button>
            </a>
            <a href="{{ route('images.registries.index') }}"
                class="{{ request()->routeIs('images.registries.index') ? 'dark:text-white' : '' }}">
                <button>Registries</button>
            </a>
        </nav>
    </div>
</div>

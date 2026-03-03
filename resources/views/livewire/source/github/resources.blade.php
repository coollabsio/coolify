<div>
    <div class="flex flex-col sm:flex-row sm:items-center gap-2">
        <h1>GitHub App</h1>
    </div>
    <div class="subtitle">{{ data_get($github_app, 'name') }}</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-4 overflow-x-scroll sm:overflow-x-hidden scrollbar min-h-10 whitespace-nowrap pt-2">
            <a class="{{ request()->routeIs('source.github.show') ? 'dark:text-white' : '' }}"
                href="{{ route('source.github.show', ['github_app_uuid' => data_get($github_app, 'uuid')]) }}"
                {{ wireNavigate() }}>
                General
            </a>
            <a class="{{ request()->routeIs('source.github.permissions-events') ? 'dark:text-white' : '' }}"
                href="{{ route('source.github.permissions-events', ['github_app_uuid' => data_get($github_app, 'uuid')]) }}"
                {{ wireNavigate() }}>
                Permissions & Events
            </a>
            <a class="{{ request()->routeIs('source.github.resources') ? 'dark:text-white' : '' }}"
                href="{{ route('source.github.resources', ['github_app_uuid' => data_get($github_app, 'uuid')]) }}"
                {{ wireNavigate() }}>
                Resources
            </a>
        </nav>
    </div>

    <livewire:source.github.tabs.resources :github-app-uuid="data_get($github_app, 'uuid')"
        :key="'source-github-tab-resources-'.data_get($github_app, 'uuid')" />
</div>

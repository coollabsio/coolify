@if ($destination->getMorphClass() === 'App\\Models\\StandaloneDocker')
    <div class="navbar-main">
        <nav class="flex shrink-0 gap-6 items-center whitespace-nowrap scrollbar min-h-10">
            <a class="{{ request()->routeIs('destination.show') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('destination.show', ['destination_uuid' => $destination->uuid]) }}">
                General
            </a>
            <a class="{{ request()->routeIs('destination.resources') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('destination.resources', ['destination_uuid' => $destination->uuid]) }}">
                Resources
            </a>
        </nav>
    </div>
@endif

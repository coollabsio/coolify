<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1>Notifications</h1>
            <div class="subtitle">All notifications from {{ currentTeam()->name }}</div>
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="flex items-center gap-2 mb-6 pb-4 border-b border-neutral-300 dark:border-coolgray-200">
        @if (!empty($filterOptions['channels']))
            <x-dropdown>
                <x-slot:title>
                    {{ $selectedChannel ?: 'All Channels' }}
                </x-slot:title>
                <div class="flex flex-col">
                    <button wire:click="$set('selectedChannel', '')"
                            class="dropdown-item {{ !$selectedChannel ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                        All Channels
                    </button>
                    @foreach ($filterOptions['channels'] as $channel)
                        <button wire:click="$set('selectedChannel', '{{ $channel }}')"
                                class="dropdown-item {{ $selectedChannel === $channel ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                            {{ ucfirst($channel) }}
                        </button>
                    @endforeach
                </div>
            </x-dropdown>
        @endif

        @if (!empty($filterOptions['eventTypes']))
            <x-dropdown>
                <x-slot:title>
                    {{ $selectedEventType ?: 'All Events' }}
                </x-slot:title>
                <div class="flex flex-col">
                    <button wire:click="$set('selectedEventType', '')"
                            class="dropdown-item {{ !$selectedEventType ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                        All Events
                    </button>
                    @foreach ($filterOptions['eventTypes'] as $eventType)
                        <button wire:click="$set('selectedEventType', '{{ $eventType }}')"
                                class="dropdown-item {{ $selectedEventType === $eventType ? 'bg-neutral-100 dark:bg-coolgray-100' : '' }}">
                            {{ str_replace('_', ' ', ucwords($eventType, '_')) }}
                        </button>
                    @endforeach
                </div>
            </x-dropdown>
        @endif

        @if ($selectedChannel || $selectedEventType)
            <button wire:click="clearFilters"
                    class="px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-neutral-100 dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-200 rounded-md hover:bg-neutral-200 dark:hover:bg-coolgray-200 transition-colors">
                Clear Filters
            </button>
        @endif
    </div>

    {{-- Notifications List --}}
    @if ($notifications->count() > 0)
        <div class="space-y-2">
            @foreach ($notifications as $notification)
                <div class="coolbox p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="px-2 py-0.5 text-xs font-medium rounded bg-neutral-100 dark:bg-coolgray-100 text-gray-700 dark:text-gray-300">
                                    {{ ucfirst($notification->channel) }}
                                </span>
                                <span class="px-2 py-0.5 text-xs font-medium rounded bg-neutral-100 dark:bg-coolgray-100 text-gray-700 dark:text-gray-300">
                                    {{ str_replace('_', ' ', ucwords($notification->event_type, '_')) }}
                                </span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $notification->created_at->diffForHumans() }}
                                </span>
                            </div>
                            @if ($notification->title)
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">
                                    {{ $notification->title }}
                                </h3>
                            @endif
                            @if ($notification->message)
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ $notification->message }}
                                </p>
                            @endif
                            @if ($notification->metadata)
                                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    @if (isset($notification->metadata['application_name']))
                                        <div>Application: {{ $notification->metadata['application_name'] }}</div>
                                    @endif
                                    @if (isset($notification->metadata['project']))
                                        <div>Project: {{ $notification->metadata['project'] }}</div>
                                    @endif
                                    @if (isset($notification->metadata['environment']))
                                        <div>Environment: {{ $notification->metadata['environment'] }}</div>
                                    @endif
                                    @if (isset($notification->metadata['deployment_url']))
                                        <div>
                                            <a href="{{ $notification->metadata['deployment_url'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline">
                                                View Deployment
                                            </a>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-6">
            {{ $notifications->links() }}
        </div>
    @else
        <div class="coolbox p-8 text-center">
            <p class="text-gray-500 dark:text-gray-400">No notifications found.</p>
            @if ($selectedChannel || $selectedEventType)
                <button wire:click="clearFilters" class="mt-4 text-sm text-blue-600 dark:text-blue-400 hover:underline">
                    Clear filters to see all notifications
                </button>
            @endif
        </div>
    @endif
</div>

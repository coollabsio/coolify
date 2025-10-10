<div wire:poll.3000ms x-data="{
    expanded: @entangle('expanded')
}" class="fixed bottom-0 z-60 mb-4 left-0 lg:left-56 ml-4">
    @if ($this->deploymentCount > 0)
        <div class="relative">
            <!-- Indicator Button -->
            <button @click="expanded = !expanded"
                class="flex items-center gap-2 px-4 py-2 rounded-lg shadow-lg transition-all duration-200 dark:bg-coolgray-100 bg-white dark:border dark:border-coolgray-200 hover:shadow-xl">
                <!-- Animated spinner -->
                <svg class="w-4 h-4 text-coollabs dark:text-warning animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none"
                    viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                        stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                    </path>
                </svg>

                <!-- Deployment count -->
                <span class="text-sm font-medium dark:text-neutral-200 text-gray-800">
                    {{ $this->deploymentCount }} {{ Str::plural('deployment', $this->deploymentCount) }}
                </span>

                <!-- Expand/collapse icon -->
                <svg class="w-4 h-4 transition-transform duration-200 dark:text-neutral-400 text-gray-600"
                    :class="{ 'rotate-180': expanded }" xmlns="http://www.w3.org/2000/svg" fill="none"
                    viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <!-- Expanded deployment list -->
            <div x-show="expanded" x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-2"
                x-cloak
                class="absolute bottom-full mb-2 w-80 max-h-96 overflow-y-auto rounded-lg shadow-xl dark:bg-coolgray-100 bg-white dark:border dark:border-coolgray-200">

                <div class="p-4 space-y-3">
                    @foreach ($this->deployments as $deployment)
                        <a href="{{ $deployment->deployment_url }}"
                            class="flex items-start gap-3 p-3 rounded-lg dark:bg-coolgray-200 bg-gray-50 transition-all duration-200 hover:ring-2 hover:ring-coollabs dark:hover:ring-warning cursor-pointer">
                            <!-- Status indicator -->
                            <div class="flex-shrink-0 mt-1">
                                @if ($deployment->status === 'in_progress')
                                    <svg class="w-4 h-4 text-coollabs dark:text-warning animate-spin"
                                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                            stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                        </path>
                                    </svg>
                                @else
                                    <svg class="w-4 h-4 dark:text-neutral-400 text-gray-500"
                                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                @endif
                            </div>

                            <!-- Deployment info -->
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium dark:text-neutral-200 text-gray-900 truncate">
                                    {{ $deployment->application_name }}
                                </div>
                                <p class="text-xs dark:text-neutral-400 text-gray-600 mt-1">
                                    {{ $deployment->application?->environment?->project?->name }} / {{ $deployment->application?->environment?->name }}
                                </p>
                                <p class="text-xs dark:text-neutral-400 text-gray-600">
                                    {{ $deployment->server_name }}
                                </p>
                                @if ($deployment->pull_request_id)
                                    <p class="text-xs dark:text-neutral-400 text-gray-600">
                                        PR #{{ $deployment->pull_request_id }}
                                    </p>
                                @endif
                                <p class="text-xs mt-1 capitalize"
                                    :class="{
                                        'text-coollabs dark:text-warning': '{{ $deployment->status }}' === 'in_progress',
                                        'dark:text-neutral-400 text-gray-500': '{{ $deployment->status }}' === 'queued'
                                    }">
                                    {{ str_replace('_', ' ', $deployment->status) }}
                                </p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>

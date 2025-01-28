<div>
    <x-slot:title>{{ data_get_str($application, 'name')->limit(10) }} > Deployments | Coolify</x-slot>
    <h1>Deployments</h1>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />
    <div class="flex flex-col gap-2 pb-10" @if (!$skip) wire:poll.5000ms='reload_deployments' @endif>
        <div class="flex items-end gap-2 pt-4">
            <h2>Deployments <span class="text-xs">({{ $deployments_count }})</span></h2>
            @if ($deployments_count > 0)
                <x-forms.button disabled="{{ !$show_prev }}" wire:click="previous_page('{{ $default_take }}')">
                    <svg class="w-6 h-6" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m14 6l-6 6l6 6z" />
                    </svg>
                </x-forms.button>
                <x-forms.button disabled="{{ !$show_next }}" wire:click="next_page('{{ $default_take }}')">
                    <svg class="w-6 h-6" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m10 18l6-6l-6-6z" />
                    </svg>
                </x-forms.button>
            @endif
        </div>
        @if ($deployments_count > 0)
            <form class="flex items-end gap-2">
                <x-forms.input id="pull_request_id" label="Pull Request"></x-forms.input>
                <x-forms.button type="submit">Filter</x-forms.button>
            </form>
        @endif
        @forelse ($deployments as $deployment)
            <div @class([
                'p-2 border-l-2 bg-white dark:bg-coolgray-100',
                'border-blue-500/50 border-dashed' => data_get($deployment, 'status') === 'in_progress',
                'border-purple-500/50 border-dashed' => data_get($deployment, 'status') === 'queued',
                'border-white border-dashed' => data_get($deployment, 'status') === 'cancelled-by-user',
                'border-error' => data_get($deployment, 'status') === 'failed',
                'border-success' => data_get($deployment, 'status') === 'finished',
            ])>
                <a href="{{ $current_url . '/' . data_get($deployment, 'deployment_uuid') }}" wire:navigate class="block">
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2 mb-2">
                            <span @class([
                                'px-3 py-1 rounded-md text-xs font-medium shadow-sm',
                                'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' => data_get($deployment, 'status') === 'in_progress',
                                'bg-purple-100/80 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300' => data_get($deployment, 'status') === 'queued',
                                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' => data_get($deployment, 'status') === 'failed',
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => data_get($deployment, 'status') === 'finished',
                                'bg-gray-100 text-gray-700 dark:bg-gray-600/30 dark:text-gray-300' => data_get($deployment, 'status') === 'cancelled-by-user',
                            ])>
                                @php
                                    $statusText = match(data_get($deployment, 'status')) {
                                        'finished' => 'Success',
                                        'in_progress' => 'In Progress',
                                        'cancelled-by-user' => 'Cancelled',
                                        'queued' => 'Queued',
                                        default => ucfirst(data_get($deployment, 'status'))
                                    };
                                @endphp
                                {{ $statusText }}
                            </span>
                        </div>
                        @if(data_get($deployment, 'status') !== 'queued')
                            <div class="text-gray-600 dark:text-gray-400 text-sm">
                                Started: {{ formatDateInServerTimezone(data_get($deployment, 'created_at'), data_get($application, 'destination.server')) }}
                                @if($deployment->status !== 'in_progress' && $deployment->status !== 'cancelled-by-user')
                                    <br>Ended: {{ formatDateInServerTimezone(data_get($deployment, 'finished_at'), data_get($application, 'destination.server')) }}
                                    <br>Duration: {{ calculateDuration(data_get($deployment, 'created_at'), data_get($deployment, 'finished_at')) }}
                                    <br>Finished {{ \Carbon\Carbon::parse(data_get($deployment, 'finished_at'))->diffForHumans() }}
                                @elseif($deployment->status === 'in_progress')
                                    <br>Running for: {{ calculateDuration(data_get($deployment, 'created_at'), now()) }}
                                @endif
                            </div>
                        @endif

                        <div class="text-gray-600 dark:text-gray-400 text-sm mt-2">
                            @if (data_get($deployment, 'commit'))
                                <div x-data="{ expanded: false }">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">Commit:</span>
                                        <a wire:navigate.prevent
                                           href="{{ $application->gitCommitLink(data_get($deployment, 'commit')) }}"
                                           target="_blank"
                                           class="underline">
                                            {{ substr(data_get($deployment, 'commit'), 0, 7) }}
                                        </a>
                                        @if (!$deployment->commitMessage())
                                            <span class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">
                                                @if (data_get($deployment, 'is_webhook'))
                                                    Webhook
                                                    @if (data_get($deployment, 'pull_request_id'))
                                                        | Pull Request #{{ data_get($deployment, 'pull_request_id') }}
                                                    @endif
                                                @elseif (data_get($deployment, 'pull_request_id'))
                                                    Pull Request #{{ data_get($deployment, 'pull_request_id') }}
                                                @elseif (data_get($deployment, 'rollback') === true)
                                                    Rollback
                                                @elseif (data_get($deployment, 'is_api'))
                                                    API
                                                @else
                                                    Manual
                                                @endif
                                            </span>
                                        @endif
                                        @if ($deployment->commitMessage())
                                            <span class="text-gray-600 dark:text-gray-400">-</span>
                                            <a wire:navigate.prevent
                                               href="{{ $application->gitCommitLink(data_get($deployment, 'commit')) }}"
                                               target="_blank"
                                               class="text-gray-600 dark:text-gray-400 truncate max-w-md underline">
                                                {{ Str::before($deployment->commitMessage(), "\n") }}
                                            </a>
                                            @if ($deployment->commitMessage() !== Str::before($deployment->commitMessage(), "\n"))
                                                <button @click="expanded = !expanded"
                                                    class="text-gray-600 dark:text-gray-400 flex items-center gap-1">
                                                    <svg x-bind:class="{'rotate-180': expanded}" class="w-4 h-4 transition-transform" viewBox="0 0 24 24">
                                                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9l6 6l6-6"/>
                                                    </svg>
                                                </button>
                                            @endif
                                            <span class="bg-gray-200/70 dark:bg-gray-600/20 px-2 py-0.5 rounded-md text-xs text-gray-800 dark:text-gray-100 border border-gray-400/30">
                                                @if (data_get($deployment, 'is_webhook'))
                                                    Webhook
                                                    @if (data_get($deployment, 'pull_request_id'))
                                                        | Pull Request #{{ data_get($deployment, 'pull_request_id') }}
                                                    @endif
                                                @elseif (data_get($deployment, 'pull_request_id'))
                                                    Pull Request #{{ data_get($deployment, 'pull_request_id') }}
                                                @elseif (data_get($deployment, 'rollback') === true)
                                                    Rollback
                                                @elseif (data_get($deployment, 'is_api'))
                                                    API
                                                @else
                                                    Manual
                                                @endif
                                            </span>
                                        @endif
                                    </div>
                                    @if ($deployment->commitMessage())
                                        <div x-show="expanded"
                                             x-transition:enter="transition ease-out duration-200"
                                             x-transition:enter-start="opacity-0 transform -translate-y-2"
                                             x-transition:enter-end="opacity-100 transform translate-y-0"
                                             class="mt-2 ml-4 text-gray-600 dark:text-gray-400">
                                            {{ Str::after($deployment->commitMessage(), "\n") }}
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @if (data_get($deployment, 'server_name') && $application->additional_servers->count() > 0)
                            <div class="text-gray-600 dark:text-gray-400 text-sm mt-2">
                                Server: {{ data_get($deployment, 'server_name') }}
                            </div>
                        @endif
                    </div>
                </a>
            </div>
        @empty
            <div>No deployments found</div>
        @endforelse
    </div>
</div>

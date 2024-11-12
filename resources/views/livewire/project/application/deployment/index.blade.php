<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Deployments | Coolify
    </x-slot>
    <h1>Deployments</h1>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />
    {{-- <livewire:project.application.deployment.show :application="$application" :deployments="$deployments" :deployments_count="$deployments_count" /> --}}
    <div class="flex flex-col gap-2 pb-10"
        @if ($skip == 0) wire:poll.5000ms='reload_deployments' @endif>
        <div class="flex items-end gap-2 pt-4">
            <h2>Deployments <span class="text-xs">({{ $deployments_count }})</span></h2>
            @if ($deployments_count > 0)
                <x-forms.button disabled="{{ !$show_prev }}" wire:click="previous_page('{{ $default_take }}')"><svg
                        class="w-6 h-6" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="m14 6l-6 6l6 6z" />
                    </svg></x-forms.button>
                <x-forms.button disabled="{{ !$show_next }}" wire:click="next_page('{{ $default_take }}')"><svg
                        class="w-6 h-6" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="m10 18l6-6l-6-6z" />
                    </svg></x-forms.button>
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
                'dark:bg-coolgray-100 p-2 border-l-2 transition-colors hover:no-underline box-without-bg-without-border bg-white flex-col cursor-pointer dark:hover:text-neutral-400 dark:hover:bg-coolgray-200',
                'border-warning border-dashed ' =>
                    data_get($deployment, 'status') === 'in_progress' ||
                    data_get($deployment, 'status') === 'cancelled-by-user',
                'border-error border-dashed ' =>
                    data_get($deployment, 'status') === 'failed',
                'border-success' => data_get($deployment, 'status') === 'finished',
            ])
                x-on:click.stop="goto('{{ $current_url . '/' . data_get($deployment, 'deployment_uuid') }}')">
                <div class="flex flex-col justify-start">
                    <div class="flex gap-1">
                        {{ $deployment->created_at }} UTC
                        <span class=" dark:text-warning">></span>
                        {{ $deployment->status }}
                    </div>
                    @if (data_get($deployment, 'is_webhook') || data_get($deployment, 'pull_request_id'))
                        <div class="flex items-center gap-1">
                            @if (data_get($deployment, 'is_webhook'))
                                Webhook
                            @endif
                            @if (data_get($deployment, 'pull_request_id'))
                                @if (data_get($deployment, 'is_webhook'))
                                    |
                                @endif
                                Pull Request #{{ data_get($deployment, 'pull_request_id') }}
                            @endif
                            @if (data_get($deployment, 'commit'))
                                <div class="dark:hover:text-white"
                                    x-on:click.stop="goto('{{ $application->gitCommitLink(data_get($deployment, 'commit')) }}')">
                                    <div class="text-xs underline">
                                        @if ($deployment->commitMessage())
                                            ({{ data_get_str($deployment, 'commit')->limit(7) }} -
                                            {{ $deployment->commitMessage() }})
                                        @else
                                            {{ data_get_str($deployment, 'commit')->limit(7) }}
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="flex items-center gap-1">
                            @if (data_get($deployment, 'rollback') === true)
                                Rollback
                            @else
                                @if (data_get($deployment, 'is_api'))
                                    API
                                @else
                                    Manual
                                @endif
                            @endif
                            @if (data_get($deployment, 'commit'))
                                <div class="dark:hover:text-white"
                                    x-on:click.stop="goto('{{ $application->gitCommitLink(data_get($deployment, 'commit')) }}')">
                                    <div class="text-xs underline">
                                        @if ($deployment->commitMessage())
                                            ({{ data_get_str($deployment, 'commit')->limit(7) }} -
                                            {{ $deployment->commitMessage() }})
                                        @else
                                            {{ data_get_str($deployment, 'commit')->limit(7) }}
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                    @if (data_get($deployment, 'server_name') && $application->additional_servers->count() > 0)
                        <div class="flex gap-1">
                            Server: {{ data_get($deployment, 'server_name') }}
                        </div>
                    @endif
                </div>

                <div class="flex flex-col" x-data="elapsedTime('{{ $deployment->deployment_uuid }}', '{{ $deployment->status }}', '{{ $deployment->created_at }}', '{{ $deployment->updated_at }}')">
                    <div>
                        @if ($deployment->status !== 'in_progress')
                            Finished <span x-text="measure_since_started()">0s</span> ago in
                            <span class="font-bold" x-text="measure_finished_time()">0s</span>
                        @else
                            Running for <span class="font-bold" x-text="measure_since_started()">0s</span>
                        @endif

                    </div>
                </div>
            </div>
        @empty
            <div class="">No deployments found</div>
        @endforelse

        @if ($deployments_count > 0)
            <script src="https://cdn.jsdelivr.net/npm/dayjs@1/dayjs.min.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/dayjs@1/plugin/utc.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/dayjs@1/plugin/relativeTime.js"></script>
            <script>
                function goto(url) {
                    window.location.href = url;
                };
                let timers = {};

                dayjs.extend(window.dayjs_plugin_utc);
                dayjs.extend(window.dayjs_plugin_relativeTime);

                Alpine.data('elapsedTime', (uuid, status, created_at, updated_at) => ({
                    finished_time: 'calculating...',
                    started_time: 'calculating...',
                    init() {
                        if (timers[uuid]) {
                            clearInterval(timers[uuid]);
                        }
                        if (status === 'in_progress') {
                            timers[uuid] = setInterval(() => {
                                this.finished_time = dayjs().diff(dayjs.utc(created_at),
                                    'second') + 's'
                            }, 1000);
                        } else {
                            let seconds = dayjs.utc(updated_at).diff(dayjs.utc(created_at), 'second')
                            this.finished_time = seconds + 's';
                        }
                    },
                    measure_finished_time() {
                        if (this.finished_time > 2000) {
                            return 0;
                        } else {
                            return this.finished_time;
                        }
                    },
                    measure_since_started() {
                        return dayjs.utc(created_at).fromNow(true); // "true" prevents the "ago" suffix
                    },
                }))
            </script>
        @endif
    </div>
</div>

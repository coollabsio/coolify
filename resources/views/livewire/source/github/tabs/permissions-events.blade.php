<div>
    <div class="flex flex-col gap-2">
        <div class="flex flex-col sm:flex-row items-start sm:items-end gap-2">
            <h2>Permissions & Events</h2>
            @if (data_get($github_app, 'installation_id'))
                @can('view', $github_app)
                    <x-forms.button wire:click.prevent="checkPermissions">Refetch</x-forms.button>
                    <a href="{{ getPermissionsPath($github_app) }}">
                        <x-forms.button>
                            Update
                            <x-external-link />
                        </x-forms.button>
                    </a>
                @endcan
            @endif
        </div>

        @if (!data_get($github_app, 'installation_id'))
            <div class="text-sm opacity-70">
                Install the GitHub App first to manage permissions and webhook events.
            </div>
        @else
            <div class="flex flex-col sm:flex-row gap-2">
                <x-forms.input id="contents" helper="read - mandatory." label="Content" readonly placeholder="N/A" />
                <x-forms.input id="metadata" helper="read - mandatory." label="Metadata" readonly placeholder="N/A" />
                <x-forms.input id="pullRequests"
                    helper="write access needed to use deployment status update in previews." label="Pull Request"
                    readonly placeholder="N/A" />
                <x-forms.input id="organizationSelfHostedRunners"
                    helper="write access needed to use GitHub Actions self-hosted runners." label="Runners" readonly
                    placeholder="N/A" />
            </div>
            <h3 class="pt-4">Webhook Events</h3>
            @if ($webhookEvents)
                <div class="flex flex-wrap gap-2">
                    @foreach ($webhookEvents as $event)
                        <span class="px-2 py-1 text-xs font-mono rounded dark:bg-coolgray-200 bg-neutral-200">{{ $event }}</span>
                    @endforeach
                </div>
                @php
                    $missingEvents = $github_app->missingWebhookEvents();
                @endphp
                @if (!empty($missingEvents))
                    <div class="text-xs text-warning">
                        Missing required events (will be auto-enabled on Refetch): {{ implode(', ', $missingEvents) }}
                    </div>
                @endif
            @else
                <div class="text-xs opacity-70">
                    No webhook event data yet. Click Refetch above to fetch current events.
                </div>
            @endif
        @endif
    </div>
</div>

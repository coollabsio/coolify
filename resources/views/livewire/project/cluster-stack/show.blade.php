<div wire:poll.5000ms>
    <x-slot:title>
        {{ str($stack->name)->limit(10) }} > Stack | Coolify
    </x-slot>

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">{{ $stack->name }}</h1>
            <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                Stack on {{ $stack->cluster?->name ?? 'a cluster' }}.
                Its services reach each other by their service names, for example
                <span class="font-mono">{{ $services[0]['service'] ?? 'db' }}</span>, in
                <span class="font-mono">{{ $stack->searchDomain() }}</span>.
            </p>
            @if ($stack->hasGitSource())
                <p class="mt-1 truncate text-xs text-neutral-500 dark:text-fg-dim">
                    From <span class="font-mono">{{ $stack->git_repository }}</span>,
                    branch <span class="font-mono">{{ $stack->git_branch }}</span>,
                    <span class="font-mono">/{{ ltrim(trim((string) $stack->base_directory, '/').($stack->compose_location ?? ''), '/') }}</span>.
                    @if (filled($stack->commit_sha))
                        Commit <span class="font-mono" title="{{ $stack->commit_sha }}">{{ substr($stack->commit_sha, 0, 7) }}</span>.
                    @endif
                </p>
                @if (filled($composeChanges))
                    <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                        Compose file changed at
                        <span class="font-mono" title="{{ $composeChanges['commit_sha'] }}">{{ substr($composeChanges['commit_sha'], 0, 7) }}</span>@foreach ($composeChanges['parts'] as $part), {{ $part['label'] }} <span class="font-mono">{{ implode(', ', $part['services']) }}</span>@endforeach.
                        @if ($composeChanges['kept_volumes'] !== [])
                            Kept the volumes that left the file, with their data:
                            <span class="font-mono">{{ implode(', ', $composeChanges['kept_volumes']) }}</span>.
                        @endif
                    </p>
                @endif
            @endif
        </div>
        @can('deploy', $stack)
            <x-forms.button wire:click="deploy" :disabled="in_array($stack->deploy_status, ['queued', 'deploying'], true)">
                {{ in_array($stack->deploy_status, ['queued', 'deploying'], true) ? 'Deploying…' : 'Deploy' }}
            </x-forms.button>
        @endcan
    </div>

    @if ($stack->deploy_status === 'failed' && filled($stack->deploy_error))
        <x-callout type="danger" title="The stack deployment failed" class="mb-4">
            {{ $stack->deploy_error }}
        </x-callout>
    @elseif (in_array($stack->deploy_status, ['queued', 'deploying'], true))
        <x-callout type="info" title="Deploying" class="mb-4">
            {{ $stack->hasGitSource() ? 'Each service with build: builds first. Then each service deploys after the services it depends on.' : 'Each service deploys after the services it depends on.' }}
        </x-callout>
    @endif

    <x-application.settings-section title="Services"
        helper="Each service is a cluster application with its own deployments, logs, terminal, and backups.">
        <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
            @forelse ($services as $service)
                <a href="{{ $service['href'] }}" {{ wireNavigate() }} wire:key="cluster-stack-service-{{ $service['uuid'] }}"
                    class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <h4 class="truncate text-sm font-semibold text-black dark:text-fg">{{ $service['service'] }}</h4>
                        @if ($service['build'] !== null)
                            <p class="mt-1 truncate text-xs text-neutral-500 dark:text-fg-dim">
                                Builds <span class="font-mono">{{ $service['build'] }}</span>@if ($service['image']), deployed <span class="font-mono">{{ $service['image'] }}</span>@endif
                            </p>
                        @else
                            <p class="mt-1 truncate font-mono text-xs text-neutral-500 dark:text-fg-dim">{{ $service['image'] }}</p>
                        @endif
                        @if ($service['depends_on'] !== [])
                            <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                                Waits for
                                {{ collect($service['depends_on'])->map(fn ($condition, $dependency) => $dependency.' ('.str($condition)->after('service_')->replace('_', ' ').')')->implode(', ') }}
                            </p>
                        @endif
                        @foreach ($service['domains'] as $domain)
                            <p class="mt-1 truncate text-xs text-neutral-500 dark:text-fg-dim">{{ $domain }}</p>
                        @endforeach
                    </div>
                    <div class="flex shrink-0 items-center gap-3 text-xs text-neutral-500 dark:text-fg-dim">
                        @if ($service['server'])
                            <span>{{ $service['server'] }}</span>
                        @endif
                        <x-status-badge :status="ucfirst($service['status'])" :type="$service['statusType']" />
                    </div>
                </a>
            @empty
                <p class="text-sm text-neutral-500 dark:text-fg-dim">This stack has no services.</p>
            @endforelse
        </div>
    </x-application.settings-section>

    @if ($volumes !== [])
        @php($statusTypes = ['ready' => 'success', 'missing' => 'error', 'not created' => 'neutral', 'unknown' => 'neutral'])
        <x-application.settings-section id="cluster-stack-volumes" title="Shared volumes"
            helper="Volumes that several services of this stack mount. A shared volume lives on one server: every service that mounts it runs there, and moving one of them moves all of them with the data.">
            <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
                @foreach ($volumes as $volume)
                    <div wire:key="cluster-stack-volume-{{ $volume['uuid'] }}"
                        class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <h4 class="truncate text-sm font-semibold text-black dark:text-fg">{{ $volume['name'] }}</h4>
                            @forelse ($volume['mounts'] as $mount)
                                <p class="mt-1 truncate text-xs text-neutral-500 dark:text-fg-dim">
                                    {{ $mount['service'] }}: <span class="font-mono">{{ $mount['path'] }}</span>{{ $mount['readOnly'] ? ' (read-only)' : '' }}
                                </p>
                            @empty
                                <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">No service mounts this volume.</p>
                            @endforelse
                        </div>
                        <div class="flex shrink-0 items-center gap-3 text-xs text-neutral-500 dark:text-fg-dim">
                            @if ($volume['server'])
                                <span>{{ $volume['server'] }}</span>
                            @endif
                            @if ($volume['receiving'])
                                <x-status-badge status="Moving" type="warning" pulse />
                            @else
                                <x-status-badge :status="str($volume['status'])->ucfirst()" :type="$statusTypes[$volume['status']] ?? 'neutral'" />
                            @endif
                            @if ($volume['mounts'] === [])
                                @can('update', $stack)
                                    <x-modal-confirmation class="!w-auto shrink-0" title="Delete volume data?" buttonTitle="Delete data"
                                        isErrorButton canGate="update" :canResource="$stack"
                                        submitAction="deleteVolume('{{ $volume['uuid'] }}')" :actions="[
                                            'The data of this volume is permanently deleted on '.($volume['server'] ?? 'its server').'.',
                                            'Retained copies on other servers are deleted too.',
                                        ]" :confirmWithPassword="false" confirmationText="{{ $volume['name'] }}"
                                        confirmationLabel="Enter the volume name to confirm" shortConfirmationLabel="Volume name"
                                        step2ButtonText="Delete data">
                                        <x-slot:trigger>
                                            <button type="button" class="button text-red-500! dark:text-red-400!" title="Delete the data of {{ $volume['name'] }}">
                                                Delete data
                                            </button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @endcan
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>
    @endif

    @can('delete', $stack)
        <x-application.settings-section title="Danger zone" helper="Destructive resource actions cannot be undone.">
            <x-danger-zone title="Delete stack">
                <p>
                    Permanently delete <strong class="font-semibold text-black dark:text-fg">{{ $stack->name }}</strong>
                    and every service of it: their containers, volumes, domains, and environment variables.
                </p>
                <x-slot:action>
                    <x-modal-confirmation title="Delete stack?" buttonTitle="Delete stack" isErrorButton
                        submitAction="delete"
                        :actions="['Permanently delete every service of this stack and remove its containers and volumes from every server.']"
                        confirmationText="{{ $stack->name }}"
                        confirmationLabel="Enter the stack name to confirm permanent deletion"
                        shortConfirmationLabel="Stack name" />
                </x-slot:action>
            </x-danger-zone>
        </x-application.settings-section>
    @endcan
</div>

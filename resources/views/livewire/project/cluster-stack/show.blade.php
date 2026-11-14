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
        </div>
        @can('update', $stack)
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
            Each service deploys after the services it depends on.
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
                        <p class="mt-1 truncate font-mono text-xs text-neutral-500 dark:text-fg-dim">{{ $service['image'] }}</p>
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

<div>
    <x-slot:title>
        Dashboard | Coolify
    </x-slot>
    @if (session('error'))
        <span x-data x-init="$wire.emit('error', '{{ session('error') }}')" />
    @endif
    <h1>Dashboard</h1>
    <div class="subtitle">Your self-hosted infrastructure.</div>

    <section class="mt-4">
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
            <x-dashboard.kpi-card
                label="Servers"
                :total="$stats['servers']['total']"
                :active="$stats['servers']['active']"
                :inactive="$stats['servers']['inactive']"
                :href="route('server.index')"
            />
            <x-dashboard.kpi-card
                label="Projects"
                :total="$stats['projects']['total']"
                :active="$stats['projects']['active']"
                :inactive="$stats['projects']['inactive']"
            />
            <x-dashboard.kpi-card
                label="Applications"
                :total="$stats['applications']['total']"
                :active="$stats['applications']['active']"
                :inactive="$stats['applications']['inactive']"
            />
            <x-dashboard.kpi-card
                label="Services"
                :total="$stats['services']['total']"
                :active="$stats['services']['active']"
                :inactive="$stats['services']['inactive']"
            />
            <x-dashboard.kpi-card
                label="Databases"
                :total="$stats['databases']['total']"
                :active="$stats['databases']['active']"
                :inactive="$stats['databases']['inactive']"
            />
        </div>
    </section>

    @if ($stats['projects']['total'] === 0 && $stats['servers']['total'] === 0)
        <section class="mt-6">
            @if ($privateKeys->count() === 0)
                <div class="flex flex-col gap-1">
                    <div class="font-bold dark:text-warning">No private keys found.</div>
                    <div class="flex items-center gap-1 flex-wrap">
                        Before you can add your server, first
                        <x-modal-input buttonTitle="add" title="New Private Key">
                            <livewire:security.private-key.create from="server" />
                        </x-modal-input>
                        a private key or go to the
                        <a class="underline dark:text-white" href="{{ route('onboarding') }}" {{ wireNavigate() }}>onboarding</a>
                        page.
                    </div>
                </div>
            @else
                <div class="flex flex-col gap-1">
                    <div class="font-bold dark:text-warning">Get started with Coolify.</div>
                    <div class="flex items-center gap-1 flex-wrap">
                        <x-modal-input buttonTitle="Add" title="New Project">
                            <livewire:project.add-empty />
                        </x-modal-input>
                        your first project,
                        <x-modal-input buttonTitle="Add" title="New Server" :closeOutside="false">
                            <livewire:server.create />
                        </x-modal-input>
                        your first server, or go to the
                        <a class="underline dark:text-white" href="{{ route('onboarding') }}" {{ wireNavigate() }}>onboarding</a>
                        page.
                    </div>
                </div>
            @endif
        </section>
    @endif

    <section class="mt-8 grid gap-8 pb-10 lg:grid-cols-2"
        @if ($this->hasActiveDeployments || $servers->isNotEmpty()) wire:poll.5000ms="refreshStats" @endif>
        <div class="min-w-0">
            <h3 class="pb-2">Latest Deployments</h3>
            <div class="flex w-full max-h-96 flex-col gap-2 overflow-y-auto scrollbar">
                @forelse ($this->latestDeployments as $deployment)
                    <x-dashboard.deployment-row
                        :deployment="$deployment"
                        :application="$deployment->application"
                    />
                @empty
                    <div class="text-neutral-500 dark:text-neutral-400">
                        No deployments yet.
                        @if ($stats['applications']['total'] === 0)
                            <a class="underline dark:text-white" href="{{ route('onboarding') }}" {{ wireNavigate() }}>Set up your first application</a>
                            to see deployment history here.
                        @endif
                    </div>
                @endforelse
            </div>
        </div>

        <div class="min-w-0">
            <div class="flex items-center justify-between gap-2 pb-2">
                <h3>Connected Servers</h3>
                @if ($servers->isNotEmpty())
                    <a href="{{ route('server.index') }}" {{ wireNavigate() }}
                        class="text-xs font-medium underline dark:text-white hover:opacity-80">
                        View all
                    </a>
                @endif
            </div>
            <div class="flex w-full max-h-96 flex-col gap-2 overflow-y-auto scrollbar">
                @forelse ($servers as $server)
                    <x-dashboard.server-row :server="$server" wire:key="server-{{ $server->id }}" />
                @empty
                    <div class="text-neutral-500 dark:text-neutral-400">
                        No servers connected yet.
                        @if ($privateKeys->count() > 0)
                            <x-modal-input buttonTitle="Add" title="New Server" :closeOutside="false">
                                <livewire:server.create />
                            </x-modal-input>
                            your first server.
                        @else
                            <a class="underline dark:text-white" href="{{ route('onboarding') }}" {{ wireNavigate() }}>Complete onboarding</a>
                            to add a server.
                        @endif
                    </div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="mt-8">
        <div class="pb-2">
            <h3>Down without healthcheck</h3>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                These stopped resources have no healthcheck configured, so Coolify cannot alert you automatically when they fail.
            </p>
        </div>
        <div class="flex w-full max-h-96 flex-col gap-2 overflow-y-auto scrollbar">
            @forelse ($downWithoutHealthcheck as $alert)
                <x-dashboard.healthcheck-alert-row :alert="$alert" wire:key="healthcheck-alert-{{ $alert['type'] }}-{{ $alert['name'] }}" />
            @empty
                <div class="text-neutral-500 dark:text-neutral-400">
                    No stopped resources without healthcheck detected.
                </div>
            @endforelse
        </div>
    </section>
</div>

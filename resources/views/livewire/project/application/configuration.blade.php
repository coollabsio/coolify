<div>
    <h1>Configuration</h1>
    <livewire:project.application.heading :application="$application" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex h-full pt-6">
        <div class="flex flex-col gap-4 xl:w-48">
            <a :class="activeTab === 'general' && 'text-white'"
                @click.prevent="activeTab = 'general'; window.location.hash = 'general'" href="#">General</a>
            @if ($application->destination->server->isSwarm())
                <a :class="activeTab === 'swarm' && 'text-white'"
                    @click.prevent="activeTab = 'swarm'; window.location.hash = 'swarm'" href="#">Swarm
                    Configuration</a>
            @endif
            <a :class="activeTab === 'advanced' && 'text-white'"
                @click.prevent="activeTab = 'advanced'; window.location.hash = 'advanced'" href="#">Advanced</a>
            @if ($application->build_pack !== 'static')
                <a :class="activeTab === 'environment-variables' && 'text-white'"
                    @click.prevent="activeTab = 'environment-variables'; window.location.hash = 'environment-variables'"
                    href="#">Environment
                    Variables</a>
            @endif
            @if ($application->build_pack !== 'static' && $application->build_pack !== 'dockercompose')
                <a :class="activeTab === 'storages' && 'text-white'"
                    @click.prevent="activeTab = 'storages'; window.location.hash = 'storages'" href="#">Storages
                </a>
            @endif
            @if ($application->git_based())
                <a :class="activeTab === 'source' && 'text-white'"
                    @click.prevent="activeTab = 'source'; window.location.hash = 'source'" href="#">Source</a>
            @endif
            <a :class="activeTab === 'server' && 'text-white'"
                @click.prevent="activeTab = 'server'; window.location.hash = 'server'" href="#">Server
            </a>

            <a :class="activeTab === 'scheduled-tasks' && 'text-white'"
                @click.prevent="activeTab = 'scheduled-tasks'; window.location.hash = 'scheduled-tasks'"
                href="#">Scheduled Tasks
            </a>

            <a :class="activeTab === 'webhooks' && 'text-white'"
                @click.prevent="activeTab = 'webhooks'; window.location.hash = 'webhooks'" href="#">Webhooks
            </a>
            @if ($application->git_based() && $application->build_pack !== 'static')
                <a :class="activeTab === 'previews' && 'text-white'"
                    @click.prevent="activeTab = 'previews'; window.location.hash = 'previews'" href="#">Preview
                    Deployments
                </a>
            @endif
            @if ($application->build_pack !== 'static' && $application->build_pack !== 'dockercompose')
                <a :class="activeTab === 'health' && 'text-white'"
                    @click.prevent="activeTab = 'health'; window.location.hash = 'health'" href="#">Healthchecks
                </a>
            @endif
            <a :class="activeTab === 'rollback' && 'text-white'"
                @click.prevent="activeTab = 'rollback'; window.location.hash = 'rollback'" href="#">Rollback
            </a>
            @if ($application->build_pack !== 'dockercompose')
                <a :class="activeTab === 'resource-limits' && 'text-white'"
                    @click.prevent="activeTab = 'resource-limits'; window.location.hash = 'resource-limits'"
                    href="#">Resource Limits
                </a>
            @endif

            <a :class="activeTab === 'danger' && 'text-white'"
                @click.prevent="activeTab = 'danger'; window.location.hash = 'danger'" href="#">Danger Zone
            </a>
        </div>
        <div class="w-full pl-8">
            <div x-cloak x-show="activeTab === 'general'" class="h-full">
                <livewire:project.application.general :application="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'swarm'" class="h-full">
                <livewire:project.application.swarm :application="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'advanced'" class="h-full">
                <livewire:project.application.advanced :application="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'environment-variables'">
                <livewire:project.shared.environment-variable.all :resource="$application" />
            </div>
            @if ($application->git_based())
                <div x-cloak x-show="activeTab === 'source'">
                    <livewire:project.application.source :application="$application" />
                </div>
            @endif
            <div x-cloak x-show="activeTab === 'server'">
                <livewire:project.shared.destination :resource="$application" :servers="$servers" />
            </div>
            <div x-cloak x-show="activeTab === 'storages'">
                <livewire:project.service.storage :resource="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'webhooks'">
                <livewire:project.shared.webhooks :resource="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'previews'">
                <livewire:project.application.previews :application="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'health'">
                <livewire:project.shared.health-checks :resource="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'rollback'">
                <livewire:project.application.rollback :application="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'resource-limits'">
                <livewire:project.shared.resource-limits :resource="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'scheduled-tasks'">
                <livewire:project.shared.scheduled-task.all :resource="$application" />
            </div>
            <div x-cloak x-show="activeTab === 'danger'">
                <livewire:project.shared.danger :resource="$application" />
            </div>
        </div>
    </div>
</div>

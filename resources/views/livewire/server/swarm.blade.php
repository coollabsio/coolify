<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Swarm | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="swarm" />

        <div class="application-settings-form w-full">
            <x-application.settings-section id="server-swarm-section" title="Docker Swarm"
                helper="Legacy Docker Swarm role configuration for this server.">
                <x-slot:actions>
                    <x-deprecated-badge />
                </x-slot:actions>

                <x-callout type="warning" title="Docker Swarm support is deprecated">
                    {{ config('deprecations.swarm') }}
                    <a class="font-medium underline" href="https://coolify.io/docs/knowledge-base/docker/swarm"
                        target="_blank">Read the migration guidance.</a>
                </x-callout>

                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="isSwarmManager" label="Manager role"
                        helper="Managers control scheduling and cluster state." onChange="instantSave"
                        :options="[
                            ['value' => false, 'label' => 'Not a Swarm manager'],
                            ['value' => true, 'label' => 'Swarm manager'],
                        ]"
                        :disabled="$server->settings->is_swarm_worker || !auth()->user()->can('update', $server)" />
                    <x-forms.listbox id="isSwarmWorker" label="Worker role"
                        helper="Workers run tasks assigned by a Swarm manager." onChange="instantSave"
                        :options="[
                            ['value' => false, 'label' => 'Not a Swarm worker'],
                            ['value' => true, 'label' => 'Swarm worker'],
                        ]"
                        :disabled="$server->settings->is_swarm_manager || !auth()->user()->can('update', $server)" />
                </div>
            </x-application.settings-section>
        </div>
    </div>
</div>

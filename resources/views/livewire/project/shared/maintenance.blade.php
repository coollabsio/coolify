<form wire:submit="submit">
    <x-unsaved-bar action="submit" targets="maintenancePage" />
    <x-application.settings-section id="maintenance-section" title="Maintenance mode"
        helper="Show a maintenance page with a 503 status on all domains of this resource. The containers keep running, and the change applies without a redeploy.">
        @if ($this->isSupported)
            <div class="flex flex-col gap-4">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox canGate="update" :canResource="$resource" id="isMaintenanceEnabled"
                        label="Maintenance mode" onChange="instantSaveMaintenance" :options="[
                            ['value' => false, 'label' => 'Off: serve the resource'],
                            ['value' => true, 'label' => 'On: show the maintenance page'],
                        ]" />
                </div>
                <x-forms.textarea canGate="update" :canResource="$resource" id="maintenancePage" rows="10" monospace
                    label="Custom maintenance page (HTML)"
                    placeholder="Leave empty to use the Coolify default maintenance page."
                    helper="One self-contained HTML file (max {{ \App\Models\Server::PROXY_ERROR_PAGE_MAX_BYTES / 1024 }} KB) with inline CSS and images." />
                @can('update', $resource)
                    <div class="flex gap-2">
                        <x-forms.button type="button" wire:click="resetMaintenancePage">Reset to
                            default</x-forms.button>
                    </div>
                @endcan
            </div>
        @else
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Maintenance mode needs a Traefik or Caddy proxy on a
                server that is not part of a Docker Swarm.</p>
        @endif
    </x-application.settings-section>
</form>

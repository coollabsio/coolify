<form wire:submit="submit">
    <x-unsaved-bar action="submit" targets="maintenancePage" />
    <x-application.settings-section id="maintenance-section" title="Maintenance mode"
        helper="Show a maintenance page with a 503 status on all domains of this resource. The containers keep running, and the change applies without a redeploy.">
        @if ($this->isSupported)
            <x-slot:badges>
                {{-- The inline wrapper puts the badge text on the same line as the title text. --}}
                <span>
                    @if ($isMaintenanceEnabled)
                        <x-status-badge label="On" type="success" />
                    @else
                        <x-status-badge label="Off" type="neutral" />
                    @endif
                </span>
            </x-slot:badges>
            @can('update', $resource)
                <x-slot:actions>
                    <x-modal-confirmation wire:key="maintenance-toggle-{{ $isMaintenanceEnabled ? 'on' : 'off' }}"
                        :ignoreWire="false" submitAction="toggleMaintenance" :confirmWithText="false"
                        :confirmWithPassword="false" :isErrorButton="! $isMaintenanceEnabled"
                        :title="$isMaintenanceEnabled ? 'Disable maintenance mode?' : 'Enable maintenance mode?'"
                        :buttonTitle="$isMaintenanceEnabled ? 'Disable maintenance' : 'Enable maintenance'"
                        :step2ButtonText="$isMaintenanceEnabled ? 'Disable maintenance' : 'Enable maintenance'"
                        :warningMessage="$isMaintenanceEnabled
                            ? 'Visitors can reach this resource again right away.'
                            : 'Visitors cannot reach this resource until you disable maintenance mode.'"
                        :actions="$isMaintenanceEnabled
                            ? ['All domains of this resource serve the resource again.']
                            : ['All domains of this resource show the maintenance page with a 503 status.', 'The containers keep running.']" />
                </x-slot:actions>
            @endcan
            <div class="flex flex-col gap-4">
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

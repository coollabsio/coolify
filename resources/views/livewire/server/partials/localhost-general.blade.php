                <form wire:submit.prevent="submit" class="application-settings-form flex flex-col gap-6">
                    <x-unsaved-bar action="submit" />
                    <x-application.settings-section id="server-overview-section" title="Server overview"
                        helper="Operating system and hardware details for the server running this Coolify instance.">
                        <x-slot:actions>
                            @if ($server->server_metadata)
                                <x-forms.button type="button" class="size-8! px-0!"
                                    wire:click="refreshServerMetadata" title="Refresh server details">
                                    <x-reicon name="refresh" class="size-3.5" />
                                </x-forms.button>
                            @endif
                            <x-status-badge :status="$server->isFunctional() ? 'Ready' : 'Validation required'"
                                :type="$server->isFunctional() ? 'success' : 'warning'" />
                        </x-slot:actions>

                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                <x-reicon name="servers" class="size-4.5" />
                            </div>
                            <div>
                                <p class="text-sm font-medium text-neutral-950 dark:text-fg">Localhost</p>
                                <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                    @if ($server->isFunctional())
                                        The server is reachable, validated, and ready to host resources.
                                    @else
                                        Validate the local Docker connection before using this server.
                                    @endif
                                </p>
                            </div>
                        </div>

                        @if ($server->server_metadata)
                            @include('livewire.server.partials.server-details', ['server' => $server])
                        @else
                            <div class="mt-4 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                <x-forms.button type="button" wire:click="refreshServerMetadata">
                                    <x-reicon name="refresh" class="size-3.5" />
                                    Fetch server details
                                </x-forms.button>
                            </div>
                        @endif
                    </x-application.settings-section>

                    @if ($server->validation_logs)
                        <x-application.settings-section title="Previous validation output"
                            helper="The latest output produced while checking this server.">
                            <div
                                class="max-h-72 overflow-auto rounded-lg bg-neutral-950 p-4 font-mono text-xs leading-5 text-neutral-300">
                                {!! $server->validation_logs !!}
                            </div>
                        </x-application.settings-section>
                    @endif

                    <x-application.settings-section id="server-connection-section" title="Connection"
                        helper="Configure how Coolify identifies and connects to this server.">
                        <x-slot:actions>
                            <x-forms.button type="button" wire:click.prevent="checkLocalhostConnection"
                                canGate="update" :canResource="$server">
                                <x-reicon name="refresh" class="size-3.5" />
                                Validate connection
                            </x-forms.button>
                        </x-slot:actions>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.input canGate="update" :canResource="$server" id="name" label="Name"
                                required :disabled="$isValidating" />
                            <x-forms.input canGate="update" :canResource="$server" id="description"
                                label="Description" :disabled="$isValidating" />
                        </div>

                        <div class="mt-4 grid gap-4 lg:grid-cols-3">
                            <x-forms.input canGate="update" :canResource="$server" type="password" id="ip"
                                label="IP address or domain"
                                helper="Enter a hostname or IP address without http:// or https://."
                                required :disabled="$isValidating" />
                            <x-forms.input canGate="update" :canResource="$server" id="user" label="SSH user"
                                required :disabled="$isValidating" />
                            <x-forms.input canGate="update" :canResource="$server" type="number" id="port"
                                label="SSH port" required :disabled="$isValidating" />
                        </div>

                        <div class="mt-4 grid gap-4 lg:grid-cols-3">
                            <x-forms.input canGate="update" :canResource="$server" type="number"
                                id="connectionTimeout" label="Connection timeout"
                                helper="Seconds to wait before an SSH connection fails." min="1" max="300"
                                required :disabled="$isValidating" />
                            <x-forms.searchable-listbox id="serverTimezone" label="Server timezone"
                                helper="Used for backup schedules, cron jobs, and displayed timestamps."
                                searchPlaceholder="Search timezones" emptyText="No matching timezone"
                                :options="collect($this->timezones)->map(fn ($timezone) => [
                                    'value' => $timezone,
                                    'label' => $timezone,
                                ])->all()" :disabled="$isValidating || !auth()->user()->can('update', $server)" />
                            <x-forms.input canGate="update" :canResource="$server"
                                placeholder="https://example.com" id="wildcardDomain" label="Wildcard domain"
                                helper="New resources can receive generated subdomains from this domain."
                                :disabled="$isValidating" />
                        </div>
                    </x-application.settings-section>
                </form>

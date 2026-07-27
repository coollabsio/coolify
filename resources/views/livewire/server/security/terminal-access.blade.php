<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Terminal Access | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar-security :server="$server" :parameters="$parameters" />

        <div class="application-settings-form w-full">
            <x-application.settings-section id="server-terminal-access-section" title="Terminal access"
                helper="Control dashboard terminal access for this server and its containers.">
                <x-slot:actions>
                    <x-status-badge :status="$isTerminalEnabled ? 'Enabled' : 'Disabled'"
                        :type="$isTerminalEnabled ? 'success' : 'error'" />
                </x-slot:actions>

                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                            <x-reicon name="terminal" class="size-4" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-neutral-950 dark:text-fg">
                                {{ $isTerminalEnabled ? 'Dashboard terminal is available' : 'Dashboard terminal is blocked' }}
                            </p>
                            <p class="mt-1 max-w-xl text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                This setting applies to every user, including administrators and the team owner.
                                Only administrators and owners can change it.
                            </p>
                        </div>
                    </div>

                    @if (auth()->user()->isAdmin())
                        <div wire:key="terminal-access-change-{{ $isTerminalEnabled }}">
                            <x-modal-confirmation title="Confirm Terminal Access Change?"
                                temporaryDisableTwoStepConfirmation
                                buttonTitle="{{ $isTerminalEnabled ? 'Disable terminal' : 'Enable terminal' }}"
                                submitAction="toggleTerminal" :actions="[
                                    $isTerminalEnabled
                                        ? 'Disable terminal access for this server and all of its containers.'
                                        : 'Enable terminal access for this server and all of its containers.',
                                    'The change takes effect immediately for every user.',
                                ]" confirmationText="{{ $server->name }}"
                                shortConfirmationLabel="Server Name"
                                step3ButtonText="{{ $isTerminalEnabled ? 'Disable Terminal' : 'Enable Terminal' }}"
                                isHighlightedButton />
                        </div>
                    @endif
                </div>
            </x-application.settings-section>
        </div>
    </div>
</div>

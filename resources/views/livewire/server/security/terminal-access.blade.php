<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Terminal Access | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar-security :server="$server" :parameters="$parameters" />
        <div class="w-full">
            <div>
                <div class="flex items-center gap-2">
                    <h2>Terminal Access</h2>
                </div>
                <div class="mb-4">Control terminal access for this server and its containers.</div>
            </div>

            <div class="flex items-center gap-2">
                <h3>Terminal Access</h3>
                <x-helper
                    helper="Control whether terminal access is available for this server and its containers.<br/>Only team
                    administrators and owners can modify this setting." />
                @if ($isTerminalEnabled)
                    <span
                        class="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                        Enabled
                    </span>
                @else
                    <span
                        class="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                        Disabled
                    </span>
                @endif
            </div>
            <div class="flex flex-col gap-4">
                <div class="flex items-center gap-4 pt-4">
                    @if (auth()->user()->isAdmin())
                        <div wire:key="terminal-access-change-{{ $isTerminalEnabled }}" class="pb-4">
                            <x-modal-confirmation title="Confirm Terminal Access Change?"
                                temporaryDisableTwoStepConfirmation
                                buttonTitle="{{ $isTerminalEnabled ? 'Disable Terminal' : 'Enable Terminal' }}"
                                submitAction="toggleTerminal" :actions="[
                                    $isTerminalEnabled
                                        ? 'This will disable terminal access for this server and all its containers.'
                                        : 'This will enable terminal access for this server and all its containers.',
                                    $isTerminalEnabled
                                        ? 'Users will no longer be able to access terminal views from the UI.'
                                        : 'Users will be able to access terminal views from the UI.',
                                    'This change will take effect immediately.',
                                ]" confirmationText="{{ $server->name }}"
                                shortConfirmationLabel="Server Name"
                                step3ButtonText="{{ $isTerminalEnabled ? 'Disable Terminal' : 'Enable Terminal' }}">
                            </x-modal-confirmation>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
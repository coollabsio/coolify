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
                    <h2>{{ __('server.terminal_access') }}</h2>
                    <x-helper
                        helper="{{ __('server.terminal_access_helper') }}"/>
                    @if (auth()->user()->isAdmin())
                        <div wire:key="terminal-access-change-{{ $isTerminalEnabled }}">
                            <x-modal-confirmation title="{{ __('server.confirm_terminal_access_change') }}"
                                temporaryDisableTwoStepConfirmation
                                buttonTitle="{{ $isTerminalEnabled ? __('server.disable_terminal') : __('server.enable_terminal') }}"
                                submitAction="toggleTerminal" :actions="[
                                    $isTerminalEnabled
                                        ? __('server.disable_terminal_action_1')
                                        : __('server.enable_terminal_action_1'),
                                    $isTerminalEnabled
                                        ? __('server.disable_terminal_action_2')
                                        : __('server.enable_terminal_action_2'),
                                    __('server.change_takes_effect_immediately'),
                                ]" confirmationText="{{ $server->name }}"
                                shortConfirmationLabel="{{ __('server.name') }}"
                                step3ButtonText="{{ $isTerminalEnabled ? __('server.disable_terminal') : __('server.enable_terminal') }}"
                                isHighlightedButton>
                            </x-modal-confirmation>
                        </div>
                    @endif
                </div>
                <div class="mb-4">{{ __('server.manage_terminal_access') }}</div>
            </div>

            <div class="flex items-center gap-2">
                <h3>{{ __('server.terminal_status') }}</h3>
                @if ($isTerminalEnabled)
                    <span
                        class="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                        {{ __('server.operational') }}
                    </span>
                @else
                    <span
                        class="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                        {{ __('server.disabled') }}
                    </span>
                @endif
            </div>
        </div>
    </div>
</div>
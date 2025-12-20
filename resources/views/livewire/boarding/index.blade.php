@php use App\Enums\ProxyTypes; @endphp
<x-slot:title>
    {{ __('onboarding.title') }}
    </x-slot>
    <section class="w-full">
        <div class="flex flex-col items-center w-full space-y-8">
            @if ($currentState === 'welcome')
                <div class="w-full max-w-2xl text-center space-y-8">
                    <div class="space-y-4">
                        <h1 class="text-4xl font-bold lg:text-6xl">{{ __('onboarding.welcome_heading') }}</h1>
                        <p class="text-lg lg:text-xl dark:text-neutral-400">
                            {{ __('onboarding.welcome_subtitle') }}
                        </p>
                    </div>

                    <div class="text-left space-y-4 p-8 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                        <h2 class="text-sm font-bold uppercase tracking-wide dark:text-neutral-400">
                            {{ __('onboarding.what_youll_setup') }}
                        </h2>
                        <div class="space-y-3">
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-0.5">
                                    <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-semibold text-base dark:text-white">{{ __('onboarding.server_connection') }}</div>
                                    <div class="text-sm dark:text-neutral-400">{{ __('onboarding.server_connection_desc') }}
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-0.5">
                                    <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-semibold text-base dark:text-white">{{ __('onboarding.docker_environment') }}</div>
                                    <div class="text-sm dark:text-neutral-400">{{ __('onboarding.docker_environment_desc') }}
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-0.5">
                                    <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-semibold text-base dark:text-white">{{ __('onboarding.project_structure') }}</div>
                                    <div class="text-sm dark:text-neutral-400">{{ __('onboarding.project_structure_desc') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col items-center gap-3 pt-4">
                        <x-forms.button class="justify-center px-12 py-4 text-lg font-bold box-boarding"
                            wire:click="explanation">
                            {{ __('onboarding.lets_go') }}
                        </x-forms.button>
                        <button wire:click="skipBoarding"
                            class="text-sm dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                            {{ __('onboarding.skip_setup') }}
                        </button>
                    </div>
                </div>
            @elseif ($currentState === 'explanation')
                <x-boarding-progress :currentStep="0" />
                <x-boarding-step title="{{ __('onboarding.platform_overview') }}">
                    <x-slot:question>
                        {{ __('onboarding.platform_overview_desc') }}
                    </x-slot:question>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="{{ __('onboarding.automation') }}" /> {{ __('onboarding.automation_desc') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.self_hosted') }}" /> {{ __('onboarding.self_hosted_desc') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.monitoring_alerts') }}" /> {{ __('onboarding.monitoring_alerts_desc') }}
                        </p>
                    </x-slot:explanation>
                    <x-slot:actions>
                        <x-forms.button class="justify-center w-full lg:w-auto px-8 py-3 box-boarding"
                            wire:click="explanation">
                            {{ __('onboarding.continue') }}
                        </x-forms.button>
                    </x-slot:actions>
                </x-boarding-step>
            @elseif ($currentState === 'select-server-type')
                <x-boarding-progress :currentStep="1" />
                <x-boarding-step title="{{ __('onboarding.choose_server_type') }}">
                    <x-slot:question>
                        {{ __('onboarding.choose_server_type_desc') }}
                    </x-slot:question>
                    <x-slot:actions>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 w-full">
                            <button
                                class="group relative box-without-bg cursor-pointer hover:border-coollabs transition-all duration-200 p-6"
                                wire:target="setServerType('localhost')" wire:click="setServerType('localhost')">
                                <div class="flex flex-col gap-4 text-left">
                                    <div class="flex items-center justify-between">
                                        <svg class="size-10" xmlns="http://www.w3.org/2000/svg" fill="none"
                                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M5.25 14.25h13.5m-13.5 0a3 3 0 01-3-3m3 3a3 3 0 100 6h13.5a3 3 0 100-6m-16.5-3a3 3 0 013-3h13.5a3 3 0 013 3m-19.5 0a4.5 4.5 0 01.9-2.7L5.737 5.1a3.375 3.375 0 012.7-1.35h7.126c1.062 0 2.062.5 2.7 1.35l2.587 3.45a4.5 4.5 0 01.9 2.7m0 0a3 3 0 01-3 3m0 3h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008zm-3 6h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008z" />
                                        </svg>
                                        <span
                                            class="px-2 py-1 text-xs font-bold uppercase tracking-wide bg-neutral-100 dark:bg-coolgray-300 dark:text-neutral-400 rounded">
                                            {{ __('onboarding.quick_start') }}
                                        </span>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold mb-2">{{ __('onboarding.this_machine') }}</h3>
                                        <p class="text-sm dark:text-neutral-400">
                                            {{ __('onboarding.this_machine_desc') }}
                                        </p>
                                    </div>
                                </div>
                            </button>



                            <button
                                class="group relative box-without-bg cursor-pointer hover:border-coollabs transition-all duration-200 p-6"
                                wire:target="setServerType('remote')" wire:click="setServerType('remote')">
                                <div class="flex flex-col gap-4 text-left">
                                    <div class="flex items-center justify-between">
                                        <svg class="size-10 " xmlns="http://www.w3.org/2000/svg" fill="none"
                                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M2.25 15a4.5 4.5 0 004.5 4.5H18a3.75 3.75 0 001.332-7.257 3 3 0 00-3.758-3.848 5.25 5.25 0 00-10.233 2.33A4.502 4.502 0 002.25 15z" />
                                        </svg>
                                        <span
                                            class="px-2 py-1 text-xs font-bold uppercase tracking-wide bg-coollabs/10 dark:bg-warning/20 text-coollabs dark:text-warning rounded">
                                            {{ __('onboarding.recommended') }}
                                        </span>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold mb-2">{{ __('onboarding.remote_server') }}</h3>
                                        <p class="text-sm dark:text-neutral-400">
                                            {{ __('onboarding.remote_server_desc') }}
                                        </p>
                                    </div>
                                </div>
                            </button>
                            @can('viewAny', App\Models\CloudProviderToken::class)
                                @if ($currentState === 'select-server-type')
                                    <x-modal-input title="{{ __('onboarding.connect_hetzner') }}" isFullWidth>
                                        <x-slot:content>
                                            <div
                                                class="group relative box-without-bg cursor-pointer hover:border-coollabs transition-all duration-200 p-6 h-full min-h-[210px]">
                                                <div class="flex flex-col gap-4 text-left">
                                                    <div class="flex items-center justify-between">
                                                        <svg class="size-10" viewBox="0 0 200 200"
                                                            xmlns="http://www.w3.org/2000/svg">
                                                            <rect width="200" height="200" fill="#D50C2D" rx="8" />
                                                            <path d="M40 40 H60 V90 H140 V40 H160 V160 H140 V110 H60 V160 H40 Z"
                                                                fill="white" />
                                                        </svg>
                                                        <span
                                                            class="px-2 py-1 text-xs font-bold uppercase tracking-wide bg-coollabs/10 dark:bg-warning/20 text-coollabs dark:text-warning rounded">
                                                            {{ __('onboarding.recommended') }}
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <h3 class="text-xl font-bold mb-2">{{ __('onboarding.hetzner_cloud') }}</h3>
                                                        <p class="text-sm dark:text-neutral-400">
                                                            {{ __('onboarding.hetzner_cloud_desc') }}
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </x-slot:content>
                                        <livewire:server.new.by-hetzner :limit_reached="false" :from_onboarding="true" />
                                    </x-modal-input>
                                @endif
                            @endcan
                        </div>

                        @if (!$serverReachable)
                            <div class="mt-6 p-4 border border-error rounded-lg text-gray-800 dark:text-gray-200">
                                <h2 class="text-lg font-bold mb-2">{{ __('onboarding.server_not_reachable') }}</h2>
                                <p class="mb-4">{{ __('onboarding.check_connection_details') }}</p>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <x-forms.input placeholder="{{ __('onboarding.default_port') }}" label="{{ __('onboarding.port') }}" id="remoteServerPort"
                                        wire:model="remoteServerPort" :value="$remoteServerPort" />
                                    <div>
                                        <x-forms.input placeholder="{{ __('onboarding.default_user') }}" label="{{ __('onboarding.user') }}" id="remoteServerUser"
                                            wire:model="remoteServerUser" :value="$remoteServerUser" />
                                        <p class="text-xs mt-1">
                                            {{ __('onboarding.non_root_experimental') }}
                                            <a class="font-bold underline" target="_blank"
                                                href="https://coolify.io/docs/knowledge-base/server/non-root-user">{{ __('onboarding.docs') }}</a>
                                        </p>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <p class="mb-2">{{ __('onboarding.if_connection_correct') }}</p>
                                    <ul class="list-disc list-inside">
                                        <li>{{ __('onboarding.public_key_hint') }}</li>
                                        <li>{{ __('onboarding.skip_boarding_hint') }}</li>
                                    </ul>
                                </div>

                                <p class="mb-4">
                                    {{ __('onboarding.more_help') }} <a target="_blank" class="underline font-semibold"
                                        href="https://coolify.io/docs/knowledge-base/server/openssh">{{ __('onboarding.documentation') }}</a>.
                                </p>

                                <x-forms.input readonly id="serverPublicKey" class="mb-4"
                                    label="{{ __('onboarding.current_public_key') }}"></x-forms.input>

                                <x-forms.button class="w-full box-boarding" wire:click="saveAndValidateServer">
                                    {{ __('onboarding.check_again') }}
                                </x-forms.button>
                            </div>
                        @endif
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="{{ __('onboarding.servers_label') }}" /> {{ __('onboarding.servers_explanation') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.localhost_label') }}" /> {{ __('onboarding.localhost_explanation') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.remote_server_label') }}" /> {{ __('onboarding.remote_server_explanation') }}
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'private-key')
                <x-boarding-progress :currentStep="2" />
                <x-boarding-step title="{{ __('onboarding.ssh_authentication') }}">
                    <x-slot:question>
                        {{ __('onboarding.ssh_authentication_desc') }}
                    </x-slot:question>
                    <x-slot:actions>
                        @if ($privateKeys && $privateKeys->count() > 0)
                            <div class="w-full space-y-4">
                                <div class="p-4 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                                    <form wire:submit='selectExistingPrivateKey' class="flex flex-col gap-4">
                                        <x-forms.select label="{{ __('onboarding.existing_ssh_keys') }}" id='selectedExistingPrivateKey'>
                                            @foreach ($privateKeys as $privateKey)
                                                <option wire:key="{{ $loop->index }}" value="{{ $privateKey->id }}">
                                                    {{ $privateKey->name }}
                                                </option>
                                            @endforeach
                                        </x-forms.select>
                                        <x-forms.button type="submit" class="w-full lg:w-auto">{{ __('onboarding.use_selected_key') }}</x-forms.button>
                                    </form>
                                </div>
                                <div class="relative">
                                    <div class="absolute inset-0 flex items-center">
                                        <div class="w-full border-t border-neutral-300 dark:border-coolgray-400"></div>
                                    </div>
                                    <div class="relative flex justify-center text-sm">
                                        <div
                                            class="px-2 py-1 bg-white dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-300 rounded text-xs font-bold text-neutral-500 dark:text-neutral-400">
                                            {{ __('onboarding.or') }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 w-full">
                            <x-forms.button
                                class="justify-center h-auto py-6 box-without-bg hover:border-coollabs transition-all duration-200"
                                wire:target="setPrivateKey('own')" wire:click="setPrivateKey('own')">
                                <div class="flex flex-col items-center gap-2">
                                    <svg class="size-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                                    </svg>
                                    <div class="text-center">
                                        <h3 class="text-xl font-bold mb-2">{{ __('onboarding.use_existing_key') }}</h3>
                                        <p class="text-sm dark:text-neutral-400">{{ __('onboarding.use_existing_key_desc') }}</p>
                                    </div>
                                </div>
                            </x-forms.button>
                            <x-forms.button
                                class="justify-center h-auto py-6 box-without-bg hover:border-coollabs transition-all duration-200"
                                wire:target="setPrivateKey('create')" wire:click="setPrivateKey('create')">
                                <div class="flex flex-col items-center gap-2">
                                    <svg class="size-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z" />
                                    </svg>
                                    <div class="text-center">
                                        <h3 class="text-xl font-bold mb-2">{{ __('onboarding.generate_new_key') }}</h3>
                                        <p class="text-sm dark:text-neutral-400">{{ __('onboarding.generate_new_key_desc') }}</p>
                                    </div>
                                </div>
                            </x-forms.button>
                        </div>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="{{ __('onboarding.ssh_key_auth_label') }}" /> {{ __('onboarding.ssh_key_auth_desc') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.public_key_deployment_label') }}" /> {{ __('onboarding.public_key_deployment_desc') }}
                            <code
                                class="text-xs bg-coolgray-300 dark:bg-coolgray-400 px-1 py-0.5 rounded">~/.ssh/authorized_keys</code>
                            {{ __('onboarding.file') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.key_generation_label') }}" /> {{ __('onboarding.key_generation_desc') }}
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'create-private-key')
                <x-boarding-progress :currentStep="2" />
                <x-boarding-step title="{{ __('onboarding.ssh_key_config') }}">
                    <x-slot:question>
                        {{ __('onboarding.ssh_key_config_desc') }}
                    </x-slot:question>
                    <x-slot:actions>
                        <form wire:submit='savePrivateKey' class="flex flex-col w-full gap-4">
                            <x-forms.input required placeholder="{{ __('onboarding.key_name_placeholder') }}" label="{{ __('onboarding.key_name') }}"
                                id="privateKeyName" />
                            <x-forms.input placeholder="{{ __('onboarding.key_description_placeholder') }}" label="{{ __('onboarding.key_description') }}"
                                id="privateKeyDescription" />
                            @if ($privateKeyType === 'create')
                                <x-forms.textarea required readonly label="{{ __('onboarding.private_key') }}" id="privateKey" rows="8" />
                                <x-forms.textarea rows="7" readonly label="{{ __('onboarding.public_key') }}" id="publicKey" />
                            @else
                                <x-forms.textarea required placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" label="{{ __('onboarding.private_key') }}"
                                    id="privateKey" rows="8" />
                            @endif
                            @if ($privateKeyType === 'create')
                                <div class="p-4 bg-warning/10 border border-warning rounded-lg">
                                    <div class="flex gap-3">
                                        <svg class="size-5 text-warning flex-shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd"
                                                d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z"
                                                clip-rule="evenodd" />
                                        </svg>
                                        <div>
                                            <p class="font-bold text-warning mb-1">{{ __('onboarding.action_required') }}</p>
                                            <p class="text-sm dark:text-white text-black">
                                                {{ __('onboarding.action_required_desc') }}
                                                <code
                                                    class="text-xs bg-coolgray-300 dark:bg-coolgray-400 px-1 py-0.5 rounded">~/.ssh/authorized_keys</code>
                                                {{ __('onboarding.file') }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            @endif
                            <x-forms.button type="submit" class="w-full lg:w-auto">{{ __('onboarding.save_ssh_key') }}</x-forms.button>
                        </form>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="{{ __('onboarding.key_storage_label') }}" /> {{ __('onboarding.key_storage_desc') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.public_key_distribution_label') }}" /> {{ __('onboarding.public_key_distribution_desc') }}
                            <code
                                class="text-xs bg-coolgray-300 dark:bg-coolgray-400 px-1 py-0.5 rounded">~/.ssh/authorized_keys</code>
                            {{ __('onboarding.on_target_server') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.key_format_label') }}" /> {{ __('onboarding.key_format_desc') }}
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'create-server')
                <x-boarding-progress :currentStep="2" />
                <x-boarding-step title="{{ __('onboarding.server_config') }}">
                    <x-slot:question>
                        {{ __('onboarding.server_config_desc') }}
                    </x-slot:question>
                    <x-slot:actions>
                        <form wire:submit='saveServer' class="flex flex-col w-full gap-4">
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <x-forms.input required placeholder="{{ __('onboarding.server_name_placeholder') }}" label="{{ __('onboarding.server_name') }}"
                                    id="remoteServerName" wire:model="remoteServerName" />
                                <x-forms.input required placeholder="{{ __('onboarding.ip_address_placeholder') }}" label="{{ __('onboarding.ip_address') }}"
                                    id="remoteServerHost" wire:model="remoteServerHost" />
                            </div>
                            <x-forms.input placeholder="{{ __('onboarding.server_desc_placeholder') }}" label="{{ __('onboarding.server_desc') }}"
                                id="remoteServerDescription" wire:model="remoteServerDescription" />

                            <div x-data="{ showAdvanced: false }" class="flex flex-col gap-4">
                                <button @click="showAdvanced = !showAdvanced" type="button"
                                    class="flex items-center gap-2 text-left text-sm font-medium  hover:underline">
                                    <svg x-show="!showAdvanced" class="size-4" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z"
                                            clip-rule="evenodd" />
                                    </svg>
                                    <svg x-show="showAdvanced" class="size-4" xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                                            clip-rule="evenodd" />
                                    </svg>
                                    {{ __('onboarding.advanced_settings') }}
                                </button>
                                <div x-show="showAdvanced" x-cloak
                                    class="grid grid-cols-1 lg:grid-cols-2 gap-4 p-4 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                                    <x-forms.input placeholder="{{ __('onboarding.ssh_port_default') }}" label="{{ __('onboarding.ssh_port') }}" type="number"
                                        id="remoteServerPort" wire:model="remoteServerPort" />
                                    <div>
                                        <x-forms.input placeholder="{{ __('onboarding.ssh_user_default') }}" label="{{ __('onboarding.ssh_user') }}" id="remoteServerUser"
                                            wire:model="remoteServerUser" />
                                        <p class="mt-1 text-xs dark:text-white text-black">
                                            {{ __('onboarding.non_root_experimental') }}
                                            <a class="font-bold underline hover:text-coollabs" target="_blank"
                                                href="https://coolify.io/docs/knowledge-base/server/non-root-user">{{ __('onboarding.learn_more') }}</a>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <x-forms.button type="submit" class="w-full lg:w-auto">{{ __('onboarding.validate_connection') }}</x-forms.button>
                        </form>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="{{ __('onboarding.connection_req_label') }}" /> {{ __('onboarding.connection_req_desc') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.hostname_res_label') }}" /> {{ __('onboarding.hostname_res_desc') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.user_perms_label') }}" /> {{ __('onboarding.user_perms_desc') }}
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'validate-server')
                <x-boarding-progress :currentStep="2" />
                <x-boarding-step title="{{ __('onboarding.server_validation') }}">
                    <x-slot:question>
                        {{ __('onboarding.server_validation_desc', ['version' => $minDockerVersion]) }}
                    </x-slot:question>
                    <x-slot:actions>
                        <div class="w-full space-y-6">
                            <div
                                class="p-6 bg-neutral-50 dark:bg-coolgray-200 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                                <h3 class="font-bold text-black dark:text-white mb-4">{{ __('onboarding.validation_steps') }}</h3>
                                <div class="space-y-3">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-base dark:text-white">{{ __('onboarding.test_ssh') }}</div>
                                            <div class="text-sm dark:text-neutral-400">{{ __('onboarding.test_ssh_desc') }}</div>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-base dark:text-white">{{ __('onboarding.check_os') }}
                                            </div>
                                            <div class="text-sm dark:text-neutral-400">{{ __('onboarding.check_os_desc') }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-base dark:text-white">{{ __('onboarding.install_docker') }}</div>
                                            <div class="text-sm dark:text-neutral-400">{{ __('onboarding.install_docker_desc', ['version' => $minDockerVersion]) }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-base dark:text-white">{{ __('onboarding.configure_network') }}</div>
                                            <div class="text-sm dark:text-neutral-400">{{ __('onboarding.configure_network_desc') }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @if ($prerequisiteInstallAttempts > 0)
                                <div class="p-6 bg-neutral-50 dark:bg-coolgray-200 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                                    <h3 class="font-bold text-black dark:text-white mb-4">{{ __('onboarding.installing_prereqs') }}</h3>
                                    <livewire:activity-monitor header="{{ __('onboarding.prereqs_logs') }}" :showWaiting="false" />
                                </div>
                            @endif

                            <x-slide-over closeWithX fullScreen>
                                <x-slot:title>{{ __('onboarding.server_validation') }}</x-slot:title>
                                <x-slot:content>
                                    <livewire:server.validate-and-install :server="$this->createdServer" />
                                </x-slot:content>
                                <x-forms.button @click="slideOverOpen=true" class="w-full font-bold py-4 box-boarding"
                                    wire:click.prevent='installServer' isHighlighted>
                                    {{ __('onboarding.start_validation') }}
                                </x-forms.button>
                            </x-slide-over>
                        </div>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="{{ __('onboarding.automated_setup_label') }}" /> {{ __('onboarding.automated_setup_desc') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.version_req_label') }}" /> {{ __('onboarding.version_req_desc', ['version' => $minDockerVersion]) }}
                            <a target="_blank" class="underline hover:text-coollabs"
                                href="https://docs.docker.com/engine/install/#server">{{ __('onboarding.manual_install_guide') }}</a>
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.system_config_label') }}" /> {{ __('onboarding.system_config_desc') }}
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'create-project')
                <x-boarding-progress :currentStep="3" />
                <x-boarding-step title="{{ __('onboarding.project_setup') }}">
                    <x-slot:question>
                        @if ($projects && $projects->count() > 0)
                            {{ __('onboarding.project_setup_existing') }}
                        @else
                            {{ __('onboarding.project_setup_new') }}
                        @endif
                    </x-slot:question>
                    <x-slot:actions>
                        <div class="w-full space-y-4">
                            <x-forms.button class="justify-center w-full py-4 font-bold box-boarding"
                                wire:click="createNewProject" isHighlighted>
                                {{ __('onboarding.create_first_project') }}
                            </x-forms.button>

                            @if ($projects && $projects->count() > 0)
                                <div class="relative">
                                    <div class="absolute inset-0 flex items-center">
                                        <div class="w-full border-t border-neutral-300 dark:border-coolgray-400"></div>
                                    </div>
                                    <div class="relative flex justify-center text-sm">
                                        <span class="px-2 text-neutral-500 dark:text-neutral-400">{{ __('onboarding.or_use_existing') }}</span>
                                    </div>
                                </div>
                                <form wire:submit='selectExistingProject' class="flex flex-col gap-4">
                                    <x-forms.select label="{{ __('onboarding.existing_projects') }}" id='selectedProject'>
                                        @foreach ($projects as $project)
                                            <option wire:key="{{ $loop->index }}" value="{{ $project->id }}">
                                                {{ $project->name }}
                                            </option>
                                        @endforeach
                                    </x-forms.select>
                                    <x-forms.button type="submit" class="w-full lg:w-auto">{{ __('onboarding.use_selected_project') }}</x-forms.button>
                                </form>
                            @endif
                        </div>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="{{ __('onboarding.project_org_label') }}" /> {{ __('onboarding.project_org_desc') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.environments_label') }}" /> {{ __('onboarding.environments_desc') }}
                        </p>
                        <p>
                            <x-highlighted text="{{ __('onboarding.team_access_label') }}" /> {{ __('onboarding.team_access_desc') }}
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'create-resource')
                <x-boarding-progress :currentStep="3" />
                <div class="w-full max-w-2xl text-center space-y-8">
                    <div class="space-y-4">
                        <div class="flex justify-center">
                            <svg class="size-16 text-success" xmlns="http://www.w3.org/2000/svg" fill="none"
                                viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h1 class="text-4xl font-bold lg:text-5xl">{{ __('onboarding.setup_complete') }}</h1>
                        <p class="text-lg dark:text-neutral-400">
                            {{ __('onboarding.setup_complete_desc') }}
                        </p>
                    </div>

                    <div class="text-left space-y-4 p-8 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                        <h2 class="text-sm font-bold uppercase tracking-wide dark:text-neutral-400">
                            {{ __('onboarding.whats_configured') }}
                        </h2>
                        <div class="space-y-3">
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-0.5">
                                    <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-semibold text-base dark:text-white">{{ __('onboarding.server_label') }}: {{ $createdServer->name }}
                                    </div>
                                    <div class="text-sm dark:text-neutral-400">{{ $createdServer->ip }}</div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-0.5">
                                    <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-semibold text-base dark:text-white">{{ __('onboarding.project_label') }}:
                                        {{ $createdProject->name }}
                                    </div>
                                    <div class="text-sm dark:text-neutral-400">{{ __('onboarding.production_ready') }}</div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 mt-0.5">
                                    <svg class="size-5 text-success" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                        fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="font-semibold text-base dark:text-white">{{ __('onboarding.docker_engine') }}</div>
                                    <div class="text-sm dark:text-neutral-400">{{ __('onboarding.installed_running') }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3">
                        <x-forms.button class="justify-center w-full py-4 text-lg font-bold box-boarding"
                            wire:click="showNewResource" isHighlighted>
                            {{ __('onboarding.deploy_first_resource') }}
                        </x-forms.button>
                        <button wire:click="skipBoarding"
                            class="text-sm dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                            {{ __('onboarding.go_to_dashboard') }}
                        </button>
                    </div>
                </div>
            @endif
        </div>

        @if ($currentState !== 'welcome' && $currentState !== 'create-resource')
            <div class="flex flex-col items-center gap-4 pt-8 mt-8 border-t border-neutral-200 dark:border-coolgray-400">
                <div class="flex justify-center gap-6 text-sm">
                    <button wire:click='skipBoarding'
                        class="dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                        {{ __('onboarding.skip_setup') }}
                    </button>
                    <button wire:click='restartBoarding'
                        class="dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                        {{ __('onboarding.restart') }}
                    </button>
                </div>
                <x-modal-input title="{{ __('onboarding.need_help') }}">
                    <x-slot:content>
                        <button
                            class="text-sm dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                            {{ __('onboarding.contact_support') }}
                        </button>
                    </x-slot:content>
                    <livewire:help />
                </x-modal-input>
            </div>
        @endif
    </section>
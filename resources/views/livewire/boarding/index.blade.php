@php use App\Enums\ProxyTypes; @endphp
<x-slot:title>
    Onboarding | Coolify
    </x-slot>
    <section class="application-settings-form w-full py-6">
        <div class="flex w-full flex-col items-center space-y-6">
            @if ($currentState === 'welcome')
                <div class="w-full max-w-3xl">
                    <div class="mb-6 text-center">
                        <div
                            class="mx-auto mb-4 flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-coollabs shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-warning">
                            <x-reicon name="play-circle" class="size-5" />
                        </div>
                        <h1 class="text-2xl! font-semibold!">Welcome to Coolify</h1>
                        <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                            Connect your first server and start deploying in minutes.
                        </p>
                    </div>

                    <x-application.settings-section title="What you will set up" flush>
                        <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                            @foreach ([
                                ['icon' => 'servers', 'title' => 'Server connection', 'description' => 'Connect through SSH to host your resources.'],
                                ['icon' => 'settings', 'title' => 'Docker environment', 'description' => 'Validate and configure the deployment runtime.'],
                                ['icon' => 'projects', 'title' => 'Project structure', 'description' => 'Create a project and its first environment.'],
                            ] as $onboardingItem)
                                <div class="flex min-h-14 items-center gap-3 px-4 py-3">
                                    <span
                                        class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                        <x-reicon :name="$onboardingItem['icon']" class="size-4" />
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-[13px] font-semibold">{{ $onboardingItem['title'] }}</span>
                                        <span class="mt-0.5 block text-[11px] text-neutral-500 dark:text-fg-faint">{{ $onboardingItem['description'] }}</span>
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </x-application.settings-section>

                    <div class="mt-5 flex flex-col items-center gap-3">
                        <x-forms.button class="justify-center px-6" wire:click="explanation" isHighlighted>
                            Continue
                        </x-forms.button>
                        <button type="button" wire:click="skipBoarding"
                            class="text-[12px] text-neutral-500 transition-colors hover:text-coollabs dark:text-fg-dim dark:hover:text-warning">
                            Skip setup
                        </button>
                    </div>
                </div>
            @elseif ($currentState === 'explanation')
                <x-boarding-progress :currentStep="0" />
                <x-boarding-step title="Platform Overview">
                    <x-slot:question>
                        Coolify automates deployment and infrastructure management on your own servers. Deploy applications
                        from Git, manage databases, and monitor everything—without vendor lock-in.
                    </x-slot:question>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="Automation:" /> Coolify handles server configuration, Docker management,
                            and
                            deployments automatically.
                        </p>
                        <p>
                            <x-highlighted text="Self-hosted:" /> All data and configurations live on your infrastructure.
                            Works offline except for external integrations.
                        </p>
                        <p>
                            <x-highlighted text="Monitoring & Alerts:" /> Get real-time notifications via Discord, Telegram,
                            Email, and other platforms.
                        </p>
                    </x-slot:explanation>
                    <x-slot:actions>
                        <x-forms.button class="w-full justify-center lg:w-auto" wire:click="explanation"
                            isHighlighted>
                            Continue
                        </x-forms.button>
                    </x-slot:actions>
                </x-boarding-step>
            @elseif ($currentState === 'select-server-type')
                <x-boarding-progress :currentStep="1" />
                <x-boarding-step title="Choose Server Type">
                    <x-slot:question>
                        Select where to deploy your applications and databases. You can add more servers later.
                    </x-slot:question>
                    <x-slot:actions>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 w-full">
                            <button
                                class="group relative min-h-36 rounded-[10px] border border-neutral-200 bg-white p-4 text-left transition-colors hover:border-coollabs/35 hover:bg-coollabs/[0.03] dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-warning/25 dark:hover:bg-warning/[0.04]"
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
                                            Quick Start
                                        </span>
                                    </div>
                                    <div>
                                        <h3 class="mb-1 text-[14px] font-semibold">This machine</h3>
                                        <p class="text-sm dark:text-neutral-400">
                                            Deploy on the server running Coolify. Best for testing and single-server setups.
                                        </p>
                                    </div>
                                </div>
                            </button>



                            <button
                                class="group relative min-h-36 rounded-[10px] border border-neutral-200 bg-white p-4 text-left transition-colors hover:border-coollabs/35 hover:bg-coollabs/[0.03] dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-warning/25 dark:hover:bg-warning/[0.04]"
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
                                            Recommended
                                        </span>
                                    </div>
                                    <div>
                                        <h3 class="mb-1 text-[14px] font-semibold">Remote server</h3>
                                        <p class="text-sm dark:text-neutral-400">
                                            Connect via SSH to any server—cloud VPS, bare metal, or home infrastructure.
                                        </p>
                                    </div>
                                </div>
                            </button>
                            @can('viewAny', App\Models\CloudProviderToken::class)
                                @if ($currentState === 'select-server-type')
                                    <x-modal-input title="Connect a Hetzner Server" isFullWidth>
                                        <x-slot:content>
                                            <div
                                                class="group relative h-full min-h-36 rounded-[10px] border border-neutral-200 bg-white p-4 text-left transition-colors hover:border-coollabs/35 hover:bg-coollabs/[0.03] dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-warning/25 dark:hover:bg-warning/[0.04]">
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
                                                            Recommended
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <h3 class="mb-1 text-[14px] font-semibold">Hetzner Cloud</h3>
                                                        <p class="text-sm dark:text-neutral-400">
                                                            Deploy servers directly from your Hetzner Cloud account.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </x-slot:content>
                                        <livewire:server.new.by-hetzner :limit_reached="false" :from_onboarding="true" />
                                    </x-modal-input>
                                    <x-modal-input title="Connect a Vultr Server" isFullWidth>
                                        <x-slot:content>
                                            <div
                                                class="group relative h-full min-h-36 rounded-[10px] border border-neutral-200 bg-white p-4 text-left transition-colors hover:border-coollabs/35 hover:bg-coollabs/[0.03] dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-warning/25 dark:hover:bg-warning/[0.04]">
                                                <div class="flex flex-col gap-4 text-left">
                                                    <div class="flex items-center justify-between">
                                                        <svg class="size-10" viewBox="0 0 200 200"
                                                            xmlns="http://www.w3.org/2000/svg">
                                                            <rect width="200" height="200" fill="#007BFC" rx="8" />
                                                            <path d="M42 46 H73 L100 127 L127 46 H158 L114 154 H86 Z"
                                                                fill="white" />
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <h3 class="mb-1 text-[14px] font-semibold">Vultr Cloud</h3>
                                                        <p class="text-sm dark:text-neutral-400">
                                                            Deploy servers directly from your Vultr account.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </x-slot:content>
                                        <livewire:server.new.by-vultr :limit_reached="false" :from_onboarding="true" />
                                    </x-modal-input>
                                @endif
                            @endcan
                        </div>

                        @if (!$serverReachable)
                            <div class="mt-6 p-4 border border-error rounded-lg text-gray-800 dark:text-gray-200">
                                <h2 class="text-lg font-bold mb-2">Server is not reachable</h2>
                                <p class="mb-4">Please check the connection details below and correct them if they are
                                    incorrect.</p>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <x-forms.input placeholder="Default is 22" label="Port" id="remoteServerPort"
                                        wire:model="remoteServerPort" :value="$remoteServerPort" />
                                    <div>
                                        <x-forms.input placeholder="Default is root" label="User" id="remoteServerUser"
                                            wire:model="remoteServerUser" :value="$remoteServerUser" />
                                        <p class="text-xs mt-1">
                                            Non-root user is experimental:
                                            <a class="font-bold underline" target="_blank"
                                                href="https://coolify.io/docs/knowledge-base/server/non-root-user">docs</a>
                                        </p>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <p class="mb-2">If the connection details are correct, please ensure:</p>
                                    <ul class="list-disc list-inside">
                                        <li>The correct public key is in your <code
                                                class="bg-red-200 dark:bg-red-900 px-1 rounded-sm">~/.ssh/authorized_keys</code>
                                            file for the specified user</li>
                                        <li>Or skip the boarding process and manually add a new private key to Coolify and
                                            the server</li>
                                    </ul>
                                </div>

                                <p class="mb-4">
                                    For more help, check this <a target="_blank" class="underline font-semibold"
                                        href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a>.
                                </p>

                                <x-forms.input readonly id="serverPublicKey" class="mb-4"
                                    label="Current Public Key"></x-forms.input>

                                <x-forms.button class="w-full justify-center" wire:click="saveAndValidateServer"
                                    isHighlighted>
                                    Check again
                                </x-forms.button>
                            </div>
                        @endif
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="Servers" /> host your applications, databases, and services (collectively
                            called resources). All CPU-intensive operations run on the target server.
                        </p>
                        <p>
                            <x-highlighted text="Localhost:" /> The machine running Coolify. Not recommended for production
                            workloads due to resource contention.
                        </p>
                        <p>
                            <x-highlighted text="Remote Server:" /> Any SSH-accessible server—cloud providers (AWS, Hetzner,
                            DigitalOcean), bare metal, or self-hosted infrastructure.
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'private-key')
                <x-boarding-progress :currentStep="2" />
                <x-boarding-step title="SSH Authentication">
                    <x-slot:question>
                        Configure SSH key-based authentication for secure server access.
                    </x-slot:question>
                    <x-slot:actions>
                        @if ($privateKeys && $privateKeys->count() > 0)
                            @php
                                $privateKeyOptions = $privateKeys
                                    ->map(fn ($privateKey) => [
                                        'value' => $privateKey->id,
                                        'label' => $privateKey->name,
                                    ])
                                    ->values()
                                    ->all();
                            @endphp
                            <div class="w-full space-y-4">
                                <div
                                    class="rounded-[10px] border border-neutral-200 bg-neutral-50 p-4 dark:border-white/[0.08] dark:bg-white/[0.025]">
                                    <form wire:submit="selectExistingPrivateKey"
                                        class="flex flex-col gap-3 sm:flex-row sm:items-end">
                                        <div class="min-w-0 flex-1">
                                            <x-forms.listbox id="selectedExistingPrivateKey"
                                                label="Existing SSH key" :options="$privateKeyOptions" />
                                        </div>
                                        <x-forms.button type="submit">Use selected key</x-forms.button>
                                    </form>
                                </div>
                                <div class="relative">
                                    <div class="absolute inset-0 flex items-center">
                                        <div class="w-full border-t border-neutral-300 dark:border-coolgray-400"></div>
                                    </div>
                                    <div class="relative flex justify-center text-sm">
                                        <div
                                            class="px-2 py-1 bg-white dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-300 rounded text-xs font-bold text-neutral-500 dark:text-neutral-400">
                                            OR
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 w-full">
                            <x-forms.button
                                class="h-auto justify-center rounded-[10px]! border-neutral-200! bg-white! py-5! hover:border-coollabs/35! hover:bg-coollabs/[0.03]! dark:border-white/[0.08]! dark:bg-white/[0.025]! dark:hover:border-warning/25! dark:hover:bg-warning/[0.04]!"
                                wire:target="setPrivateKey('own')" wire:click="setPrivateKey('own')">
                                <div class="flex flex-col items-center gap-2">
                                    <svg class="size-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                                    </svg>
                                    <div class="text-center">
                                        <h3 class="mb-1 text-[14px] font-semibold">Use existing key</h3>
                                        <p class="text-sm dark:text-neutral-400">I have my own SSH key</p>
                                    </div>
                                </div>
                            </x-forms.button>
                            <x-forms.button
                                class="h-auto justify-center rounded-[10px]! border-neutral-200! bg-white! py-5! hover:border-coollabs/35! hover:bg-coollabs/[0.03]! dark:border-white/[0.08]! dark:bg-white/[0.025]! dark:hover:border-warning/25! dark:hover:bg-warning/[0.04]!"
                                wire:target="setPrivateKey('create')" wire:click="setPrivateKey('create')">
                                <div class="flex flex-col items-center gap-2">
                                    <svg class="size-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z" />
                                    </svg>
                                    <div class="text-center">
                                        <h3 class="mb-1 text-[14px] font-semibold">Generate new key</h3>
                                        <p class="text-sm dark:text-neutral-400">Create ED25519 key pair</p>
                                    </div>
                                </div>
                            </x-forms.button>
                        </div>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="SSH Key Authentication:" /> Uses public-key cryptography for secure,
                            password-less server access.
                        </p>
                        <p>
                            <x-highlighted text="Public Key Deployment:" /> Add the public key to your server's
                            <code
                                class="text-xs bg-coolgray-300 dark:bg-coolgray-400 px-1 py-0.5 rounded">~/.ssh/authorized_keys</code>
                            file.
                        </p>
                        <p>
                            <x-highlighted text="Key Generation:" /> Coolify generates ED25519 keys by default for optimal
                            security and performance.
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'create-private-key')
                <x-boarding-progress :currentStep="2" />
                <x-boarding-step title="SSH Key Configuration">
                    <x-slot:question>
                        Configure your SSH key for server authentication.
                    </x-slot:question>
                    <x-slot:actions>
                        <form wire:submit='savePrivateKey' class="flex flex-col w-full gap-4">
                            <x-forms.input required placeholder="e.g., production-server-key" label="Key Name"
                                id="privateKeyName" />
                            <x-forms.input placeholder="Optional: Note what this key is used for" label="Description"
                                id="privateKeyDescription" />
                            @if ($privateKeyType === 'create')
                                <x-forms.textarea required readonly label="Private Key" id="privateKey" rows="8" />
                                <x-forms.textarea rows="7" readonly label="Public Key" id="publicKey" />
                            @else
                                <x-forms.textarea required placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" label="Private Key"
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
                                            <p class="font-bold text-warning mb-1">Action Required</p>
                                            <p class="text-sm dark:text-white text-black">
                                                Copy the public key above and add it to your server's
                                                <code
                                                    class="text-xs bg-coolgray-300 dark:bg-coolgray-400 px-1 py-0.5 rounded">~/.ssh/authorized_keys</code>
                                                file.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            @endif
                            <x-forms.button type="submit" class="w-full lg:w-auto">Save SSH Key</x-forms.button>
                        </form>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="Key Storage:" /> Private keys are encrypted at rest in Coolify's database.
                        </p>
                        <p>
                            <x-highlighted text="Public Key Distribution:" /> Deploy the public key to
                            <code
                                class="text-xs bg-coolgray-300 dark:bg-coolgray-400 px-1 py-0.5 rounded">~/.ssh/authorized_keys</code>
                            on your target server for the specified user.
                        </p>
                        <p>
                            <x-highlighted text="Key Format:" /> Supports RSA, ED25519, ECDSA, and DSA key types in OpenSSH
                            format.
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'create-server')
                <x-boarding-progress :currentStep="2" />
                <x-boarding-step title="Server Configuration">
                    <x-slot:question>
                        Provide connection details for your remote server.
                    </x-slot:question>
                    <x-slot:actions>
                        <form wire:submit='saveServer' class="flex flex-col w-full gap-4">
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <x-forms.input required placeholder="e.g., production-app-server" label="Server Name"
                                    id="remoteServerName" wire:model="remoteServerName" />
                                <x-forms.input required placeholder="IP address or hostname" label="IP Address/Hostname"
                                    id="remoteServerHost" wire:model="remoteServerHost" />
                            </div>
                            <x-forms.input placeholder="Optional: Note what this server hosts" label="Description"
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
                                    Advanced Connection Settings
                                </button>
                                <div x-show="showAdvanced" x-cloak
                                    class="grid grid-cols-1 lg:grid-cols-2 gap-4 p-4 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                                    <x-forms.input placeholder="Default: 22" label="SSH Port" type="number"
                                        id="remoteServerPort" wire:model="remoteServerPort" />
                                    <div>
                                        <x-forms.input placeholder="Default: root" label="SSH User" id="remoteServerUser"
                                            wire:model="remoteServerUser" />
                                        <p class="mt-1 text-xs dark:text-white text-black">
                                            Non-root user support is experimental.
                                            <a class="font-bold underline hover:text-coollabs" target="_blank"
                                                href="https://coolify.io/docs/knowledge-base/server/non-root-user">Learn
                                                more</a>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <x-forms.button type="submit" class="w-full lg:w-auto">Validate Connection</x-forms.button>
                        </form>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="Connection Requirements:" /> Server must be accessible via SSH on the
                            specified port (default 22).
                        </p>
                        <p>
                            <x-highlighted text="Hostname Resolution:" /> Use IP addresses for direct connections or ensure
                            DNS resolution is configured.
                        </p>
                        <p>
                            <x-highlighted text="User Permissions:" /> Root or sudo-enabled users recommended for full
                            Docker
                            management capabilities.
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'validate-server')
                <x-boarding-progress :currentStep="2" />
                <x-boarding-step title="Server Validation">
                    <x-slot:question>
                        Coolify will automatically install Docker {{ $minDockerVersion }}+ if not present.
                    </x-slot:question>
                    <x-slot:actions>
                        <div class="w-full space-y-6">
                            <div
                                class="p-6 bg-neutral-50 dark:bg-coolgray-200 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                                <h3 class="font-bold text-black dark:text-white mb-4">Validation Steps</h3>
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
                                            <div class="font-semibold text-base dark:text-white">Test SSH Connection</div>
                                            <div class="text-sm dark:text-neutral-400">Verify key-based authentication</div>
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
                                            <div class="font-semibold text-base dark:text-white">Check OS Compatibility
                                            </div>
                                            <div class="text-sm dark:text-neutral-400">Verify supported Linux distribution
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
                                            <div class="font-semibold text-base dark:text-white">Install Docker Engine</div>
                                            <div class="text-sm dark:text-neutral-400">Auto-install if version
                                                {{ $minDockerVersion }}+ not
                                                found
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
                                            <div class="font-semibold text-base dark:text-white">Configure Network</div>
                                            <div class="text-sm dark:text-neutral-400">Set up Docker networks and proxy
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @if ($prerequisiteInstallAttempts > 0)
                                <div class="p-6 bg-neutral-50 dark:bg-coolgray-200 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                                    <h3 class="font-bold text-black dark:text-white mb-4">Installing Prerequisites</h3>
                                    <livewire:activity-monitor header="Prerequisites Installation Logs" :showWaiting="false" />
                                </div>
                            @endif

                            <x-slide-over closeWithX fullScreen>
                                <x-slot:title>Server Validation</x-slot:title>
                                <x-slot:content>
                                    <livewire:server.validate-and-install :server="$this->createdServer" />
                                </x-slot:content>
                                <x-forms.button @click="slideOverOpen=true" class="w-full justify-center"
                                    wire:click.prevent='installServer' isHighlighted>
                                    Start validation
                                </x-forms.button>
                            </x-slide-over>
                        </div>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="Automated Setup:" /> Coolify installs Docker Engine, Docker Compose, and
                            configures system requirements automatically.
                        </p>
                        <p>
                            <x-highlighted text="Version Requirements:" /> Minimum Docker Engine {{ $minDockerVersion }}.x
                            required.
                            <a target="_blank" class="underline hover:text-coollabs"
                                href="https://docs.docker.com/engine/install/#server">Manual installation guide</a>
                        </p>
                        <p>
                            <x-highlighted text="System Configuration:" /> Sets up Docker networks, proxy configuration, and
                            resource monitoring.
                        </p>
                    </x-slot:explanation>
                </x-boarding-step>
            @elseif ($currentState === 'create-project')
                <x-boarding-progress :currentStep="3" />
                <x-boarding-step title="Project Setup">
                    <x-slot:question>
                        @if ($projects && $projects->count() > 0)
                            You have existing projects. Select one or create a new project to organize your resources.
                        @else
                            Create your first project to organize applications, databases, and services.
                        @endif
                    </x-slot:question>
                    <x-slot:actions>
                        <div class="w-full space-y-4">
                            <x-forms.button class="w-full justify-center"
                                wire:click="createNewProject" isHighlighted>
                                Create "My First Project"
                            </x-forms.button>

                            @if ($projects && $projects->count() > 0)
                                @php
                                    $projectOptions = $projects
                                        ->map(fn ($project) => [
                                            'value' => $project->id,
                                            'label' => $project->name,
                                        ])
                                        ->values()
                                        ->all();
                                @endphp
                                <div class="relative">
                                    <div class="absolute inset-0 flex items-center">
                                        <div class="w-full border-t border-neutral-300 dark:border-coolgray-400"></div>
                                    </div>
                                    <div class="relative flex justify-center text-sm">
                                        <span class="px-2 text-neutral-500 dark:text-neutral-400">Or use existing</span>
                                    </div>
                                </div>
                                <form wire:submit="selectExistingProject"
                                    class="flex flex-col gap-3 sm:flex-row sm:items-end">
                                    <div class="min-w-0 flex-1">
                                        <x-forms.listbox id="selectedProject" label="Existing project"
                                            :options="$projectOptions" />
                                    </div>
                                    <x-forms.button type="submit">Use selected project</x-forms.button>
                                </form>
                            @endif
                        </div>
                    </x-slot:actions>
                    <x-slot:explanation>
                        <p>
                            <x-highlighted text="Project Organization:" /> Group related resources (apps, databases,
                            services)
                            into logical projects.
                        </p>
                        <p>
                            <x-highlighted text="Environments:" /> Each project includes a production environment by
                            default.
                            Add staging, development, or custom environments as needed.
                        </p>
                        <p>
                            <x-highlighted text="Team Access:" /> Projects inherit team permissions and can be managed
                            collaboratively.
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
                        <h1 class="text-2xl! font-semibold!">Setup complete</h1>
                        <p class="text-lg dark:text-neutral-400">
                            Your server is connected and ready. Start deploying your first resource.
                        </p>
                    </div>

                    <div class="text-left space-y-4 p-8 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                        <h2 class="text-sm font-bold uppercase tracking-wide dark:text-neutral-400">
                            What's Configured
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
                                    <div class="font-semibold text-base dark:text-white">Server: {{ $createdServer->name }}
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
                                    <div class="font-semibold text-base dark:text-white">Project:
                                        {{ $createdProject->name }}
                                    </div>
                                    <div class="text-sm dark:text-neutral-400">Production environment ready</div>
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
                                    <div class="font-semibold text-base dark:text-white">Docker Engine</div>
                                    <div class="text-sm dark:text-neutral-400">Installed and running</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3">
                        <x-forms.button class="w-full justify-center"
                            wire:click="showNewResource" isHighlighted>
                            Deploy your first resource
                        </x-forms.button>
                        <button wire:click="skipBoarding"
                            class="text-sm dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                            Go to Dashboard
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
                        Skip Setup
                    </button>
                    <button wire:click='restartBoarding'
                        class="dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                        Restart
                    </button>
                </div>
                <x-modal-input title="Need Help?">
                    <x-slot:content>
                        <button
                            class="text-sm dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                            Contact Support
                        </button>
                    </x-slot:content>
                    <livewire:help />
                </x-modal-input>
            </div>
        @endif
    </section>

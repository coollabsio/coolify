<div>
    <x-slot:title>
        {{ __('settings.title') }} | Coolify
    </x-slot>
    <x-settings.navbar />
    <div class="flex flex-col">
        <div class="flex items-center gap-2 pb-2">
            <h2>{{ __('settings.backup') }}</h2>
            @if (isset($database) && $server->isFunctional())
                <x-forms.button type="submit" wire:click="submit">
                    {{ __('common.save') }}
                </x-forms.button>
            @endif
        </div>
        <div class="pb-4">{{ __('settings.backup_configuration') }}</div>
        <div>
            @if ($server->isFunctional())
                @if (isset($database) && isset($backup))
                    <div class="flex flex-col gap-3 pb-4">
                        <div class="flex gap-2">
                            <x-forms.input label="{{ __('settings.uuid') }}" readonly id="uuid" />
                            <x-forms.input label="{{ __('input.name') }}" readonly id="name" />
                            <x-forms.input label="{{ __('common.description') }}" id="description" />
                        </div>
                        <div class="flex gap-2">
                            <x-forms.input label="{{ __('settings.user') }}" readonly id="postgres_user" />
                            <x-forms.input type="password" label="{{ __('settings.password') }}" readonly id="postgres_password" />
                        </div>
                    </div>
                    <livewire:project.database.backup-edit :backup="$backup" :s3s="$s3s" :status="data_get($database, 'status')" />
                    <div class="py-4">
                        <livewire:project.database.backup-executions :backup="$backup" />
                    </div>
                @else
                    {{ __('settings.configure_backup_first') }}
                    <x-forms.button class="mt-2" wire:click="addCoolifyDatabase">{{ __('common.configure_backup') }}</x-forms.button>
                @endif
            @else
                <div class="p-6 bg-red-500/10 rounded-lg border border-red-500/20">
                    <div class="text-red-500 font-medium mb-4">
                        {{ __('settings.backup_disabled') }}
                    </div>
                    <a href="{{ route('server.show', [$server->uuid]) }}"
                        class="text-black hover:text-gray-700 dark:text-white dark:hover:text-gray-200 underline" {{ wireNavigate() }}>
                        {{ __('settings.go_to_server_settings') }}
                    </a>
                </div>
            @endif
        </div>
    </div>
</div>

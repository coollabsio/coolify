<div>
    <x-slot:title>
        Registry Credentials | Coolify
    </x-slot>

    <x-security.settings-layout>
        <div class="application-settings-form">
            <x-application.settings-section title="Registry credentials"
                description="Logins for container registries. Cluster applications pull private images and push built images with them."
                flush>
                <x-slot:actions>
                    @can('create', App\Models\RegistryCredential::class)
                        <x-modal-input title="New Registry Credential">
                            <x-slot:content>
                                <button type="button" class="button button-highlighted">
                                    <x-reicon name="plus" class="size-3.5" />
                                    New credential
                                </button>
                            </x-slot:content>
                            <livewire:security.registry-credential-form wire:key="new-registry-credential" />
                        </x-modal-input>
                    @endcan
                </x-slot:actions>

                @if ($credentials->isEmpty())
                    <x-empty title="No registry credentials"
                        description="Add a login to deploy a private image or push built images to your registry."
                        icon-name="keys" size="sm" />
                @else
                    <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                        @foreach ($credentials as $credential)
                            <div wire:key="registry-credential-{{ $credential->uuid }}"
                                class="grid min-h-14 w-full grid-cols-[minmax(0,1fr)_minmax(0,1fr)_8rem_4.5rem] items-center gap-3 px-4 py-2.5">
                                <div class="min-w-0">
                                    <h3 class="truncate text-[13px]! font-semibold! text-black dark:text-fg">{{ $credential->name }}</h3>
                                    <p class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">{{ $credential->username }}</p>
                                </div>
                                <div class="truncate font-mono text-[12px] text-neutral-600 dark:text-fg-dim">{{ $credential->registry }}</div>
                                <div class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                    {{ $credential->workloads_count }} {{ Str::plural('application', $credential->workloads_count) }}
                                </div>
                                <div class="flex justify-end gap-1">
                                    @can('update', $credential)
                                        <x-modal-input title="Edit Registry Credential">
                                            <x-slot:content>
                                                <button type="button" class="icon-button" title="Edit registry credential"
                                                    aria-label="Edit {{ $credential->name }}">
                                                    <x-reicon name="settings" class="size-3.5" />
                                                </button>
                                            </x-slot:content>
                                            <livewire:security.registry-credential-form
                                                :registry_credential_uuid="$credential->uuid"
                                                :key="'registry-credential-form-'.$credential->uuid" />
                                        </x-modal-input>
                                    @endcan
                                    @can('delete', $credential)
                                        <x-modal-confirmation title="Delete registry credential?"
                                            submitAction="delete('{{ $credential->uuid }}')" :actions="[
                                                'This registry credential will be permanently deleted.',
                                            ]"
                                            confirmationText="{{ $credential->name }}" :confirmWithPassword="false"
                                            step2ButtonText="Delete credential">
                                            <x-slot:trigger>
                                                <button type="button"
                                                    class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                                    title="Delete registry credential" aria-label="Delete {{ $credential->name }}">
                                                    <x-reicon name="trash" class="size-3.5" />
                                                </button>
                                            </x-slot:trigger>
                                        </x-modal-confirmation>
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </x-security.settings-layout>
</div>

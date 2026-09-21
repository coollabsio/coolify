<div>
    <x-slot:title>
        Infisical | Coolify
    </x-slot>

    <x-security.settings-layout>
    @php
        $connection = $this->connection;
    @endphp

    <div class="application-settings-form w-full">
    <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Infisical</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                Keep this team's secrets in one Infisical project. One connection per team.
            </p>
        </div>
    </header>

    <x-application.settings-section id="infisical-connection-section" title="Connection"
        description="The credentials Coolify uses to authenticate against Infisical, and the project this team's secrets live in.">
        <x-slot:actions>
            @if (! $connection)
                @can('create', App\Models\InfisicalConnection::class)
                    <x-modal-input title="Connect Infisical" :closeOutside="false">
                        <x-slot:content>
                            <button type="button" class="button button-highlighted">
                                <x-reicon name="plus" class="size-3.5" />
                                Connect Infisical
                            </button>
                        </x-slot:content>
                        <livewire:security.infisical.form wire:key="infisical-connection-new" />
                    </x-modal-input>
                @endcan
            @endif
        </x-slot:actions>

        @if (! $connection)
            <x-empty size="sm" title="Infisical is not connected"
                description="Connect an Infisical project to make it the single place this team's secrets are edited." />
        @else
            <div wire:key="infisical-connection-{{ $connection->uuid }}" class="flex flex-col gap-4">
                <div class="data-table w-full">
                    <div class="data-table-row flex w-full items-center gap-2 px-3 py-2.5 text-[13px]">
                        <x-modal-input title="{{ $connection->name }}" :closeOutside="false">
                            <x-slot:content>
                                <button type="button"
                                    class="flex min-w-0 flex-1 items-center justify-between gap-3 text-left">
                                    <span class="truncate font-medium text-black dark:text-fg">{{ $connection->name }}</span>
                                    <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $connection->host }}</span>
                                </button>
                            </x-slot:content>
                            <livewire:security.infisical.form :connection="$connection" :key="'infisical-connection-form-'.$connection->uuid" />
                        </x-modal-input>

                        <div class="ml-auto flex shrink-0 items-center gap-2">
                            @if ($connection->is_enabled)
                                <span
                                    class="rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ 'Sync enabled' }}</span>
                            @else
                                <span
                                    class="rounded-md bg-neutral-100 px-2 py-0.5 text-[11px] font-semibold text-neutral-600 dark:bg-white/5 dark:text-fg-dim">{{ 'Sync off' }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Sync status. Every field here is written by
                     AdoptTeamSecretsIntoInfisical, so a queued adoption reports
                     its own progress without the screen polling anything. --}}
                @if ($connection->is_enabled)
                    <dl class="grid gap-3 text-[12px] sm:grid-cols-3">
                        <div>
                            <dt class="text-neutral-500 dark:text-fg-dim">Adoption</dt>
                            <dd class="mt-0.5 font-medium text-black dark:text-fg">
                                @if ($connection->adopted_at)
                                    Completed {{ $connection->adopted_at->diffForHumans() }}
                                @else
                                    In progress
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-neutral-500 dark:text-fg-dim">Last sync</dt>
                            <dd class="mt-0.5 font-medium text-black dark:text-fg">
                                {{ $connection->last_synced_at?->diffForHumans() ?? 'Never' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-neutral-500 dark:text-fg-dim">Status</dt>
                            <dd class="mt-0.5 font-medium text-black dark:text-fg">
                                {{ $connection->last_sync_status ? ucfirst($connection->last_sync_status) : 'Pending' }}
                            </dd>
                        </div>
                    </dl>

                    @if (filled($connection->last_sync_error))
                        <x-callout type="danger" title="Last sync reported a problem">
                            {{ $connection->last_sync_error }}
                        </x-callout>
                    @endif

                    @if ($url = $connection->secretsUrl())
                        <div class="text-[12px] text-neutral-500 dark:text-fg-dim">
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="underline">Open this
                                project in Infisical</a>
                        </div>
                    @endif
                @endif

                {{-- The consequences, stated before the user commits rather than
                     discovered afterwards. See the design spec: "Mapping
                     Coolify's four shared-variable scopes" and "Deletion". --}}
                @if (! $connection->is_enabled)
                    <x-callout type="warning" title="Read this before enabling sync">
                        <ul class="list-disc space-y-1 pl-4">
                            <li><span class="font-semibold">Coolify variable editing becomes read-only.</span> Every
                                existing variable in this team is pushed up to Infisical once, and from then on
                                Infisical is the only place a human edits a secret.</li>
                            <li><span class="font-semibold">Deleting a secret in Infisical will not remove it from
                                    Coolify</span> — or from running deployments. Sync is additive and update-only,
                                so revoking a leaked credential needs a manual step here as well.</li>
                            <li><span class="font-semibold">Team- and project-scoped variables are replicated per
                                    environment.</span> Infisical's top-level axis is the environment, so "applies
                                everywhere" is lost: each becomes an independent secret per environment, and
                                changing it means changing each one. Infisical's secret imports can restore that;
                                Coolify does not manage them.</li>
                        </ul>
                        <p class="mt-2">
                            Server-scoped shared variables are not synced and stay fully editable in Coolify.
                            Managed database passwords are covered, and — as today — changing one takes effect on
                            the next redeploy rather than rotating a running database.
                        </p>
                    </x-callout>
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    @can('update', $connection)
                        @if (! $connection->is_enabled)
                            <x-modal-confirmation title="Enable Infisical sync?" isHighlightedButton
                                buttonTitle="Enable sync" submitAction="enableConnection" :actions="[
                                    'Every existing variable in this team is pushed up into Infisical once. This runs in the background and can take a while.',
                                    'Editing variables in Coolify becomes read-only. Infisical becomes the only place a human edits a secret.',
                                    'Deleting a secret in Infisical will NOT remove it from Coolify or from running deployments — revoking a leaked credential needs a manual step here too.',
                                    'Team- and project-scoped variables are replicated per environment, so the \'applies everywhere\' property is lost.',
                                    'Server-scoped variables are not synced and stay editable. Database password changes take effect on redeploy and do not rotate a running database.',
                                ]" :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Enable sync" />
                        @else
                            <x-modal-confirmation title="Disable Infisical sync?" buttonTitle="Disable sync"
                                submitAction="disableConnection" :actions="[
                                    'Coolify stops pulling from Infisical for this team.',
                                    'Variables already synced into Coolify are kept and become editable here again.',
                                    'Nothing is deleted, in Coolify or in Infisical.',
                                ]" :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Disable sync" />
                        @endif
                    @endcan

                    @can('delete', $connection)
                        <x-modal-confirmation title="Confirm Connection Deletion?" isErrorButton buttonTitle="Delete"
                            submitAction="deleteConnection('{{ $connection->uuid }}')" :actions="[
                                'This Infisical connection will be permanently deleted, including its stored credentials.',
                                'Deleting the connection also disarms the lock: Coolify variables become editable here again.',
                                'Variables already synced into Coolify are kept — nothing deletes them, by design — but they stop being refreshed.',
                                'Nothing is removed from Infisical.',
                            ]" confirmationText="{{ $connection->name }}"
                            confirmationLabel="Enter the connection name to confirm deletion"
                            shortConfirmationLabel="Connection name" :confirmWithPassword="false"
                            step2ButtonText="Delete connection" />
                    @endcan
                </div>
            </div>
        @endif
    </x-application.settings-section>

    </div>
    </x-security.settings-layout>
</div>

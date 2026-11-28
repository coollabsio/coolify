<form wire:submit="saveName" class="flex flex-col gap-6">
    <x-application.settings-section title="Stack details"
        description="Manage the identity and the Compose configuration of this stack.">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @can('update', $stack)
                    <x-modal-input buttonTitle="{{ $stack->hasGitSource() ? 'Show Compose file' : 'Edit Compose file' }}" title="Docker Compose"
                        :closeOutside="false" :isLarge="true">
                        @if ($stack->hasGitSource())
                            <x-callout type="info" title="Compose file from Git" class="mb-3">
                                This stack reads its Compose file from the repository at each deploy. Change the file in the repository.
                            </x-callout>
                        @endif
                        <div class="compose-editor-container min-h-[24rem] overflow-hidden rounded-lg border border-neutral-200 bg-white font-mono dark:border-white/[0.10] dark:bg-[#0b0b0c]"
                            style="--editor-height: clamp(24rem, calc(100dvh - 25rem), 48rem)">
                            @if ($stack->hasGitSource())
                                <x-forms.textarea class="min-h-[24rem] font-mono" style="height: var(--editor-height)" readonly id="composeRaw" />
                            @else
                                <x-forms.textarea allowTab useMonacoEditor monacoEditorLanguage="yaml" id="composeRaw" />
                            @endif
                        </div>
                        @unless ($stack->hasGitSource())
                            <x-slot:footer>
                                <div class="flex items-center justify-end gap-2">
                                    <x-forms.button type="button" wire:click="saveCompose" wire:target="saveCompose" isHighlighted>
                                        Save changes
                                    </x-forms.button>
                                </div>
                            </x-slot:footer>
                        @endunless
                    </x-modal-input>
                @endcan
            </div>
        </x-slot:actions>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input canGate="update" :canResource="$stack" id="name" required label="Stack name" />
            <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-neutral-700 dark:text-fg-dim">Internal domain</span>
                <span class="input flex items-center font-mono text-xs" title="The services reach each other by their service names in this domain.">{{ $stack->searchDomain() }}</span>
                <span class="text-xs text-neutral-500 dark:text-fg-faint">Services reach each other by their names, for example <span class="font-mono">{{ $services[0]['service'] ?? 'db' }}</span>.</span>
            </div>
        </div>
        @if ($stack->hasGitSource())
            <p class="text-xs text-neutral-500 dark:text-fg-dim">
                From <span class="font-mono">{{ $stack->git_repository }}</span>,
                branch <span class="font-mono">{{ $stack->git_branch }}</span>,
                <span class="font-mono">/{{ ltrim(trim((string) $stack->base_directory, '/').($stack->compose_location ?? ''), '/') }}</span>.
                @if (filled($stack->commit_sha))
                    Commit <span class="font-mono" title="{{ $stack->commit_sha }}">{{ substr($stack->commit_sha, 0, 7) }}</span>.
                @endif
            </p>
        @endif
        @if (filled($composeChanges))
            <p class="text-xs text-neutral-500 dark:text-fg-dim">
                Last Compose change{{ $composeChanges['commit_sha'] !== '' ? ' ('.substr($composeChanges['commit_sha'], 0, 7).')' : '' }}:
                {{ collect($composeChanges['parts'])->map(fn (array $part): string => $part['label'].' '.implode(', ', $part['services']))->implode('; ') }}.
                @if ($composeChanges['kept_volumes'] !== [])
                    Kept the volumes that left the file, with their data:
                    <span class="font-mono">{{ implode(', ', $composeChanges['kept_volumes']) }}</span>.
                @endif
            </p>
        @endif
        @can('update', $stack)
            <div class="flex justify-end">
                <x-forms.button type="submit">Save</x-forms.button>
            </div>
        @endcan
    </x-application.settings-section>

    <x-application.settings-section id="compose-resources-section" title="Compose resources"
        description="Applications and databases defined in this stack. Each one is a cluster application with its own settings, logs, terminal, and backups." flush>
        <div class="overflow-x-auto rounded-lg">
            @if ($services === [])
                <div class="p-4">
                    <x-empty title="No compose resources" description="No services are defined in this Docker Compose file." icon-name="grid" />
                </div>
            @else
                <div class="grid min-w-[48rem] grid-cols-[minmax(14rem,1fr)_minmax(12rem,1fr)_12rem_5rem] gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                    <div>Resource</div>
                    <div>Image</div>
                    <div class="justify-self-start">Status</div>
                    <span class="text-right">Actions</span>
                </div>
            @endif
            @foreach ($services as $service)
                <div wire:key="cluster-stack-service-{{ $service['uuid'] }}"
                    x-on:click="if (! $event.target.closest('button, a')) { window.location.href = @js($service['href']) }"
                    role="link" tabindex="0" aria-label="Open {{ $service['service'] }} settings"
                    class="grid min-h-14 min-w-[48rem] cursor-pointer grid-cols-[minmax(14rem,1fr)_minmax(12rem,1fr)_12rem_5rem] items-center gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                            <x-reicon :name="$service['isDatabase'] ? 'database' : 'grid'" class="size-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ str($service['service'])->headline() }}</div>
                            @if ($service['depends_on'] !== [])
                                <div class="truncate text-xs text-neutral-500 dark:text-fg-dim">
                                    Waits for {{ collect($service['depends_on'])->map(fn ($condition, $dependency) => $dependency.' ('.str($condition)->after('service_')->replace('_', ' ').')')->implode(', ') }}
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="min-w-0 truncate font-mono text-xs text-neutral-500 dark:text-fg-faint" title="{{ $service['image'] }}">
                        {{ $service['build'] !== null ? 'Builds '.$service['build'] : $service['image'] }}
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <x-status-badge :status="ucfirst($service['status'])" :type="$service['statusType']" />
                        @if ($service['server'])
                            <span class="truncate text-xs text-neutral-500 dark:text-fg-dim">{{ $service['server'] }}</span>
                        @endif
                    </div>
                    <div class="flex items-center justify-end">
                        <x-table.row-actions label="Resource actions">
                            @if ($service['domains'] !== [] || ! $service['isDatabase'])
                                <x-table.row-action :href="$service['domainsHref']">Domains</x-table.row-action>
                            @endif
                            <x-table.row-action :href="$service['backupsHref']">Backups</x-table.row-action>
                            @if ($service['running'] && auth()->user()->can('deploy', $stack))
                                <x-table.row-action wire:click="restartService('{{ $service['uuid'] }}')">Restart</x-table.row-action>
                            @endif
                            <x-table.row-action :href="$service['href']">Settings</x-table.row-action>
                        </x-table.row-actions>
                    </div>
                </div>
            @endforeach
        </div>
    </x-application.settings-section>
</form>

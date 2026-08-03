<div class="application-settings-form flex flex-col gap-6">
    <form wire:submit="submit" class="flex flex-col gap-6">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section title="Repository"
            description="Configure the Git repository, branch, and commit Coolify deploys.">
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <a target="_blank" class="button" href="{{ $application?->gitBranchLocation }}">
                        Repository
                        <x-external-link />
                    </a>
                    @if (data_get($application, 'source.is_public') === false && $application->source instanceof \App\Models\GithubApp)
                        <a target="_blank" class="button" href="{{ getInstallationPath($application->source) }}">
                            Git app
                            <x-external-link />
                        </a>
                    @endif
                    <a target="_blank" class="button" href="{{ $application?->gitCommits }}">
                        Commits
                        <x-external-link />
                    </a>
                </div>
            </x-slot:actions>

            @if (blank($privateKeyId))
                <div
                    class="mb-4 flex items-center justify-between gap-3 rounded-lg bg-neutral-50 px-3 py-2.5 ring-1 ring-neutral-200 dark:bg-white/[0.025] dark:ring-white/[0.07]">
                    <span class="text-[12px] text-neutral-500 dark:text-fg-dim">Connected source</span>
                    <span class="text-[12px] font-medium text-neutral-900 dark:text-fg">
                        {{ data_get($application, 'source.name', 'No source connected') }}
                    </span>
                </div>
            @endif

            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input placeholder="coollabsio/coolify-example" id="gitRepository" label="Repository"
                    canGate="update" :canResource="$application" />
                <x-forms.input placeholder="main" id="gitBranch" label="Branch" canGate="update"
                    :canResource="$application" />
                <x-forms.input placeholder="HEAD" id="gitCommitSha" label="Commit SHA" canGate="update"
                    :canResource="$application" />
            </div>
        </x-application.settings-section>
    </form>

    @if (filled($privateKeyId))
        <x-application.settings-section title="Deploy key"
            description="The SSH key Coolify uses to clone this private repository.">
            <div
                class="mb-4 flex items-center justify-between gap-3 rounded-lg bg-neutral-50 px-3 py-2.5 ring-1 ring-neutral-200 dark:bg-white/[0.025] dark:ring-white/[0.07]">
                <span class="text-[12px] text-neutral-500 dark:text-fg-dim">Attached private key</span>
                <span class="text-[12px] font-medium text-neutral-900 dark:text-fg">{{ $privateKeyName }}</span>
            </div>
            @can('update', $application)
                <div class="grid gap-2 sm:grid-cols-2">
                    @forelse ($privateKeys as $key)
                        <button type="button" wire:click="setPrivateKey('{{ $key->id }}')"
                            class="flex min-h-11 items-center rounded-lg border border-neutral-200 bg-white px-3 text-left text-[12px] font-medium transition-colors hover:bg-neutral-50 dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:bg-white/[0.05]">
                            {{ $key->name }}
                        </button>
                    @empty
                        <x-empty title="No alternative private keys"
                            description="Add another private key from Keys & Tokens before switching."
                            icon-name="keys" size="sm" />
                    @endforelse
                </div>
            @endcan
        </x-application.settings-section>
    @else
        @can('update', $application)
            <x-application.settings-section title="Git source">
                <div class="grid gap-2 sm:grid-cols-2">
                    @forelse ($sources as $source)
                        <x-modal-confirmation title="Change Git source?"
                            :actions="['Change Git source to ' . $source->name]" :buttonFullWidth="true"
                            :isHighlightedButton="$application->source_id === $source->id && $application->source_type === $source->getMorphClass()"
                            :disabled="$application->source_id === $source->id && $application->source_type === $source->getMorphClass()"
                            submitAction="changeSource({{ $source->id }}, {{ $source->getMorphClass() }})"
                            :confirmWithText="true" confirmationText="Change Git Source"
                            confirmationLabel="Enter the text below to confirm changing the Git source."
                            shortConfirmationLabel="Confirmation text" :confirmWithPassword="false">
                            <x-slot:customButton>
                                <div class="flex min-w-0 flex-col text-left">
                                    <span class="truncate text-[12px] font-medium">{{ $source->name }}</span>
                                    <span class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ $source->organization ?? $source->group_name ?? 'Personal account' }}
                                    </span>
                                </div>
                            </x-slot:customButton>
                        </x-modal-confirmation>
                    @empty
                        <div class="col-span-full">
                            <x-empty title="No other sources"
                                description="Connect another Git source before moving this application."
                                icon-name="sources" size="sm">
                                <x-slot:contents>
                                    <a href="{{ route('source.all') }}" {{ wireNavigate() }} class="button">
                                        Connect source
                                    </a>
                                </x-slot:contents>
                            </x-empty>
                        </div>
                    @endforelse
                </div>
            </x-application.settings-section>
        @endcan
    @endif
</div>

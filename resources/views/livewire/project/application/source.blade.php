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
            @php
                $currentSource = $application->source;
                $currentSourceMorph = $currentSource?->getMorphClass();
                $currentSourceIsGithub = $currentSourceMorph === \App\Models\GithubApp::class;
                $currentSourceIsGitlab = $currentSourceMorph === \App\Models\GitlabApp::class;
                $currentSourceSubtitle = match (true) {
                    $currentSourceIsGithub && filled(data_get($currentSource, 'organization')) => 'GitHub · '.$currentSource->organization,
                    $currentSourceIsGithub => data_get($currentSource, 'is_public') ? 'Public GitHub' : 'GitHub · Personal account',
                    $currentSourceIsGitlab && filled(data_get($currentSource, 'group_name')) => 'GitLab · '.$currentSource->group_name,
                    $currentSourceIsGitlab => 'GitLab · Personal account',
                    default => 'Connected source',
                };
            @endphp
            <x-application.settings-section title="Git source"
                description="Switch the Git provider App Coolify uses to clone this repository.">
                <div class="grid gap-2 sm:grid-cols-2">
                    @if ($currentSource)
                        <div
                            class="relative flex min-h-16 items-center gap-3 rounded-xl border border-coollabs/30 bg-coollabs/[0.06] p-3 ring-1 ring-coollabs/20 dark:border-warning/30 dark:bg-warning/[0.08] dark:ring-warning/15"
                            aria-current="true">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-coollabs/20 bg-white text-coollabs dark:border-warning/20 dark:bg-white/[0.04] dark:text-warning">
                                <x-git-icon class="size-4" :git="$currentSourceMorph" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex min-w-0 flex-wrap items-center gap-2">
                                    <span
                                        class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ $currentSource->name }}</span>
                                    <x-status-badge label="Current" type="success" />
                                </div>
                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                    {{ $currentSourceSubtitle }}
                                </p>
                            </div>
                            <x-reicon name="check"
                                class="size-4 shrink-0 text-coollabs dark:text-warning" />
                        </div>
                    @endif

                    @forelse ($sources as $source)
                        @php
                            $sourceMorph = $source->getMorphClass();
                            $sourceIsGithub = $sourceMorph === \App\Models\GithubApp::class;
                            $sourceIsGitlab = $sourceMorph === \App\Models\GitlabApp::class;
                            $sourceSubtitle = match (true) {
                                $sourceIsGithub && filled($source->organization) => 'GitHub · '.$source->organization,
                                $sourceIsGithub => data_get($source, 'is_public') ? 'Public GitHub' : 'GitHub · Personal account',
                                $sourceIsGitlab && filled($source->group_name) => 'GitLab · '.$source->group_name,
                                $sourceIsGitlab => 'GitLab · Personal account',
                                default => 'Git source',
                            };
                        @endphp
                        <x-modal-confirmation title="Change Git source?"
                            :actions="['Change Git source to ' . $source->name]" :buttonFullWidth="true"
                            submitAction="changeSource({{ $source->id }}, {{ $sourceMorph }})"
                            :confirmWithText="true" confirmationText="Change Git Source"
                            confirmationLabel="Enter the text below to confirm changing the Git source."
                            shortConfirmationLabel="Confirmation text" :confirmWithPassword="false">
                            <x-slot:trigger>
                                <button type="button"
                                    class="group flex w-full min-h-16 items-center gap-3 rounded-xl border border-neutral-200 bg-white p-3 text-left shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:bg-neutral-50 hover:shadow-md focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14] dark:hover:bg-white/[0.05]">
                                    <div
                                        class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 transition-colors group-hover:border-neutral-300 group-hover:text-neutral-700 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim dark:group-hover:border-white/[0.12] dark:group-hover:text-fg">
                                        <x-git-icon class="size-4" :git="$sourceMorph" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-[13px] font-semibold text-black dark:text-fg">{{ $source->name }}</span>
                                        <span
                                            class="mt-0.5 block truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $sourceSubtitle }}</span>
                                    </div>
                                    <x-reicon name="arrow-right"
                                        class="size-3.5 shrink-0 text-neutral-300 opacity-0 transition-all group-hover:translate-x-0.5 group-hover:opacity-100 dark:text-fg-faint" />
                                </button>
                            </x-slot:trigger>
                        </x-modal-confirmation>
                    @empty
                        @if (! $currentSource)
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
                        @endif
                    @endforelse
                </div>
                @if ($currentSource && $sources->isEmpty())
                    <p class="mt-3 text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                        No other sources available.
                        <a href="{{ route('source.all') }}" {{ wireNavigate() }}
                            class="font-medium text-coollabs underline-offset-2 hover:underline dark:text-warning">Connect
                            another source</a>
                        to switch providers.
                    </p>
                @endif
            </x-application.settings-section>
        @endcan
    @endif
</div>

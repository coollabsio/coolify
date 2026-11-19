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

            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input placeholder="coollabsio/coolify-example" id="gitRepository" label="Repository"
                    canGate="update" :canResource="$application" />
                <x-forms.input placeholder="main" id="gitBranch" label="Branch" canGate="update"
                    :canResource="$application" />
                <x-forms.input placeholder="HEAD" id="gitCommitSha" label="Commit SHA" canGate="update"
                    :canResource="$application" />
            </div>
        </x-application.settings-section>

        @php
            $usesGitSsh = $application->gitSshHost() !== null;
        @endphp
        @if ($usesGitSsh || filled($application->git_ssh_known_hosts))
        <x-application.settings-section id="ssh-host-keys" title="SSH host keys" x-data="{ editManually: false }" x-on:known-hosts-invalid.window="editManually = true" flush
            description="The host keys that git over SSH accepts for self-hosted Git hosts. On the first deployment, Coolify saves the key that the host sends, and later deployments fail if the key changes. To accept a new key (also of a submodule host), click Fetch current host key, compare the fingerprint with the fingerprint from the administrator of the Git host, and click Accept. GitHub, GitLab.com, and Bitbucket Cloud always accept their published keys. If GitHub or Bitbucket Cloud changes its keys, click Fetch current host key to read the new keys from their official HTTPS endpoint. If GitLab.com changes its keys, click Fetch current host key and compare the fingerprint with the keys on docs.gitlab.com.">
            @can('update', $application)
                <x-slot:actions>
                    <button type="button" class="button" @click="editManually = !editManually" :aria-expanded="editManually" aria-controls="manual-host-keys">
                        <x-reicon name="edit" class="size-3.5" />
                        Edit manually
                    </button>
                    @if ($usesGitSsh || filled($application->git_ssh_known_hosts))
                        <x-forms.button type="button" wire:click="fetchHostKeys" wire:loading.attr="disabled"
                            wire:target="fetchHostKeys">
                            Fetch current host key
                        </x-forms.button>
                    @endif
                </x-slot:actions>
            @endcan
            @if ($fetchedHostKeys !== [])
                <x-callout type="warning" title="Compare these fingerprints before you accept them" class="mb-4">
                    <p>These are the keys that the Git host sends now. Accept them only if the fingerprint is the same
                        as the fingerprint from the administrator of the Git host.</p>
                    <ul class="my-2 flex flex-col gap-1">
                        @foreach ($this->fetchedHostKeyFingerprints() as $fingerprint)
                            <li class="break-all"><span class="font-medium">{{ $fingerprint['host'] }}</span>
                                {{ $fingerprint['type'] }} <span class="font-mono">{{ $fingerprint['fingerprint'] }}</span></li>
                        @endforeach
                    </ul>
                    <div class="flex flex-wrap gap-2">
                        <x-forms.button type="button" wire:click="acceptFetchedHostKeys">Accept</x-forms.button>
                        <x-forms.button type="button" wire:click="declineFetchedHostKeys">Decline</x-forms.button>
                    </div>
                </x-callout>
            @endif
            @php
                $fingerprints = [...$this->publishedHostKeyFingerprints(), ...$this->knownHostFingerprints()];
            @endphp
            @if ($fingerprints === [])
                <x-empty size="sm" icon-name="keys" title="No saved host keys"
                    description="The next deployment saves the key that the Git host sends. To save it now, click Fetch current host key." />
            @else
                <div class="data-table">
                    <div class="min-w-[36rem]">
                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_minmax(0,2fr)_1.75rem] items-center gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[13px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                        <span class="pl-11">Host</span><span>Type</span><span>Fingerprint</span><span></span>
                    </div>
                    <ul class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                    @foreach ($fingerprints as $fingerprint)
                        <li wire:key="host-key-{{ $fingerprint['id'] }}"
                            class="grid min-h-14 grid-cols-[minmax(0,1fr)_7rem_minmax(0,2fr)_1.75rem] items-center gap-3 px-4 py-2.5 transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.025]">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim"><x-reicon name="keys" class="size-4" /></div>
                                <div class="min-w-0">
                                    <span class="break-all text-[13px] font-semibold text-black dark:text-fg">{{ $fingerprint['host'] }}</span>
                                    @if ($fingerprint['published'])
                                        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">Published by the provider, used for every deployment</p>
                                    @endif
                                </div>
                            </div>
                            <span class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $fingerprint['type'] }}</span>
                            <span class="break-all font-mono text-[12px] text-neutral-500 dark:text-fg-dim">{{ $fingerprint['fingerprint'] }}</span>
                            @if ($fingerprint['published'])
                                <span></span>
                            @else
                            @can('update', $application)
                            <x-modal-confirmation class="!w-auto shrink-0" title="Remove host key?" buttonTitle="Remove"
                                isErrorButton canGate="update" :canResource="$application"
                                submitAction="removeHostKey('{{ $fingerprint['id'] }}')" :actions="[
                                    'The host key ' . $fingerprint['fingerprint'] . ' of ' . $fingerprint['host'] . ' will be removed.',
                                    'The next deployment saves the key that the Git host sends then, without a check.',
                                ]" :confirmWithPassword="false" :confirmWithText="false" step2ButtonText="Remove host key">
                                <x-slot:trigger>
                                    <button type="button"
                                        class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                        title="Remove host key" aria-label="Remove host key of {{ $fingerprint['host'] }}">
                                        <x-reicon name="trash" class="size-3.5" />
                                    </button>
                                </x-slot:trigger>
                            </x-modal-confirmation>
                            @endcan
                            @endif
                        </li>
                    @endforeach
                    </ul>
                    </div>
                </div>
            @endif
            @can('update', $application)
                <div id="manual-host-keys" x-show="editManually" x-cloak class="p-4">
                    <div>
                        <x-forms.textarea id="gitSshKnownHosts" rows="4" label="Known hosts"
                            helper="One line for each key: host key-type key. Use [host]:port for a custom SSH port. GitHub and Bitbucket Cloud use their published keys and cannot be added here. Click Save to apply."
                            placeholder="[git.example.com]:2222 ssh-ed25519 AAAA..." canGate="update"
                            :canResource="$application" />
                    </div>
                </div>
            @endcan
        </x-application.settings-section>
        @endif
        @if ($application->deploymentType() === 'other')
            <x-application.settings-section title="HTTPS credentials"
                description="The username and password or access token Coolify uses to clone a private repository from a custom Git host (Azure DevOps, Bitbucket, Gitea, and others) over HTTP(S). Coolify sends them only to the host of the repository URL and never shows the saved password or token.">
                @if ($hasGitHttpsPassword)
                    @can('update', $application)
                        <x-slot:actions>
                            <x-forms.button type="button" isError wire:click="removeGitHttpsCredential">
                                Remove credentials
                            </x-forms.button>
                        </x-slot:actions>
                    @endcan
                @endif
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input id="gitHttpsUsername" label="Username" autocomplete="off"
                        helper="Some hosts accept any username with an access token (for example Azure DevOps)."
                        canGate="update" :canResource="$application" />
                    <x-forms.input id="gitHttpsPassword" type="password" label="Password or token"
                        autocomplete="new-password"
                        placeholder="{{ $hasGitHttpsPassword ? 'Saved. Enter a new value to replace it.' : '' }}"
                        canGate="update" :canResource="$application" />
                </div>
            </x-application.settings-section>
        @endif
    </form>

    @if (filled($privateKeyId))
        <x-application.settings-section title="Deploy key"
            description="The SSH key Coolify uses to clone this private repository." flush>
            <div class="data-table">
                <div class="grid grid-cols-[minmax(0,1fr)_7rem] items-center gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[13px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                    <span class="pl-11">Private key</span>
                    <span class="text-right">Selection</span>
                </div>
                <div class="grid min-h-14 w-full grid-cols-[minmax(0,1fr)_7rem] items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.025] border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                            <x-reicon name="keys" class="size-4" />
                        </div>
                        <span class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ $privateKeyName }}</span>
                    </div>
                    <div class="flex justify-end"><x-status-badge label="Selected" type="success" /></div>
                </div>
                @can('update', $application)
                    @foreach ($privateKeys as $key)
                        <div wire:key="deploy-key-{{ $key->id }}" class="grid min-h-14 w-full grid-cols-[minmax(0,1fr)_7rem] items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.025] border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    <x-reicon name="keys" class="size-4" />
                                </div>
                                <span class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ $key->name }}</span>
                            </div>
                            <div class="flex justify-end">
                                <button type="button" wire:click="setPrivateKey({{ $key->id }})"
                                    wire:loading.attr="disabled" wire:target="setPrivateKey"
                                    title="Select {{ $key->name }}" aria-label="Select {{ $key->name }}"
                                    class="icon-button disabled:cursor-wait disabled:opacity-60">
                                    <span wire:loading.remove wire:target="setPrivateKey({{ $key->id }})"><x-reicon name="link" class="size-3.5" /></span>
                                    <span wire:loading wire:target="setPrivateKey({{ $key->id }})"><x-loading compact /><span class="sr-only">Applying {{ $key->name }}</span></span>
                                </button>
                            </div>
                        </div>
                    @endforeach
                @endcan
            </div>
        </x-application.settings-section>
    @else
        @can('update', $application)
            @php
                $currentSource = $application->source;
                $currentSourceMorph = $currentSource?->getMorphClass();
                $currentSourceIsGithub = $currentSourceMorph === \App\Models\GithubApp::class;
                $currentSourceIsGitlab = $currentSourceMorph === \App\Models\GitlabApp::class;
                $currentSourceIsGitea = $currentSourceMorph === \App\Models\GiteaApp::class;
                $currentSourceSubtitle = match (true) {
                    $currentSourceIsGithub && filled(data_get($currentSource, 'organization')) => 'GitHub · '.$currentSource->organization,
                    $currentSourceIsGithub => data_get($currentSource, 'is_public') ? 'Public GitHub' : 'GitHub · Personal account',
                    $currentSourceIsGitlab && filled(data_get($currentSource, 'group_name')) => 'GitLab · '.$currentSource->group_name,
                    $currentSourceIsGitlab => 'GitLab · Personal account',
                    $currentSourceIsGitea && filled(data_get($currentSource, 'organization')) => 'Gitea · '.$currentSource->organization,
                    $currentSourceIsGitea => 'Gitea · Personal account',
                    default => 'Connected source',
                };
            @endphp
            <x-application.settings-section title="Git source"
                description="Switch the Git provider App Coolify uses to clone this repository.">
                <div class="grid gap-2 sm:grid-cols-2">
                    @if ($currentSource)
                        <div
                            class="relative flex min-h-16 items-center gap-3 rounded-xl border border-coollabs/30 bg-coollabs/[0.06] p-3 ring-1 ring-coollabs/20 dark:border-brand-light/30 dark:bg-brand/[0.12] dark:ring-brand-light/15"
                            aria-current="true">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-coollabs/20 bg-white text-coollabs dark:border-brand-light/20 dark:bg-white/[0.04] dark:text-brand-light">
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
                        </div>
                    @endif

                    @forelse ($sources as $source)
                        @php
                            $sourceMorph = $source->getMorphClass();
                            $sourceIsGithub = $sourceMorph === \App\Models\GithubApp::class;
                            $sourceIsGitlab = $sourceMorph === \App\Models\GitlabApp::class;
                            $sourceIsGitea = $sourceMorph === \App\Models\GiteaApp::class;
                            $sourceSubtitle = match (true) {
                                $sourceIsGithub && filled($source->organization) => 'GitHub · '.$source->organization,
                                $sourceIsGithub => data_get($source, 'is_public') ? 'Public GitHub' : 'GitHub · Personal account',
                                $sourceIsGitlab && filled($source->group_name) => 'GitLab · '.$source->group_name,
                                $sourceIsGitlab => 'GitLab · Personal account',
                                $sourceIsGitea && filled($source->organization) => 'Gitea · '.$source->organization,
                                $sourceIsGitea => 'Gitea · Personal account',
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
                                    class="group flex w-full min-h-16 items-center gap-3 rounded-xl border border-neutral-200 bg-white p-3 text-left shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:bg-neutral-50 hover:shadow-md focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:border-white/[0.08] dark:bg-white/[0.05] dark:hover:border-white/[0.14] dark:hover:bg-white/[0.05]">
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
                            class="font-medium text-coollabs underline-offset-2 hover:underline dark:text-brand-light">Connect
                            another source</a>
                        to switch providers.
                    </p>
                @endif
            </x-application.settings-section>
        @endcan
    @endif
</div>

<div class="form-page">
    <form wire:submit='submit' class="flex flex-col gap-10">
        <div class="form-card">
            <div class="form-section-title">
                <h2>Source</h2>
                <div class="flex items-center gap-2">
                    @can('update', $application)
                        <x-forms.button type="submit">Save</x-forms.button>
                    @endcan
                    <div class="flex items-center gap-4 px-2">
                        <a target="_blank" class="hover:no-underline flex items-center gap-1"
                            href="{{ $application?->gitBranchLocation }}">
                            Open Repository
                            <x-external-link />
                        </a>
                        @if (data_get($application, 'source.is_public') === false)
                            <a target="_blank" class="hover:no-underline flex items-center gap-1"
                                href="{{ getInstallationPath($application->source) }}">
                                Open Git App
                                <x-external-link />
                            </a>
                        @endif
                        <a target="_blank" class="flex hover:no-underline items-center gap-1"
                            href="{{ $application?->gitCommits }}">
                            Open Commits on Git
                            <x-external-link />
                        </a>
                    </div>
                </div>
            </div>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Code source of your application.</p>

            <div class="flex flex-col gap-10">
                @if (!$privateKeyId)
                    <div>Currently connected source: <span
                            class="font-bold text-warning">{{ data_get($application, 'source.name', 'No source connected') }}</span>
                    </div>
                @endif
                <div class="flex gap-2">
                    <x-forms.input placeholder="coollabsio/coolify-example" id="gitRepository" label="Repository"
                        canGate="update" :canResource="$application" />
                    <x-forms.input placeholder="main" id="gitBranch" label="Branch" canGate="update" :canResource="$application" />
                </div>
                <div class="flex items-end gap-2">
                    <x-forms.input placeholder="HEAD" id="gitCommitSha" placeholder="HEAD" label="Commit SHA"
                        canGate="update" :canResource="$application" />
                </div>
            </div>
        </div>

        @if ($privateKeyId)
            <div class="form-subsection">
                <h3>Deploy Key</h3>
                <div class="py-2">Currently attached Private Key: <span
                        class="dark:text-warning">{{ $privateKeyName }}</span>
                </div>

                @can('update', $application)
                    <h4 class="py-2">Select another Private Key</h4>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($privateKeys as $key)
                            <x-forms.button wire:click="setPrivateKey('{{ $key->id }}')">{{ $key->name }}
                            </x-forms.button>
                        @endforeach
                    </div>
                @endcan
            </div>
        @else
            @can('update', $application)
                <div class="form-subsection">
                    <h3>Change Git Source</h3>
                    <div class="grid grid-cols-1 gap-2">
                        @forelse ($sources as $source)
                            <div wire:key="{{ $source->name }}">
                                <x-modal-confirmation title="Change Git Source" :actions="['Change git source to ' . $source->name]" :buttonFullWidth="true"
                                    :isHighlightedButton="$application->source_id === $source->id" :disabled="$application->source_id === $source->id"
                                    submitAction="changeSource({{ $source->id }}, {{ $source->getMorphClass() }})"
                                    :confirmWithText="true" confirmationText="Change Git Source"
                                    confirmationLabel="Please confirm changing the git source by entering the text below"
                                    shortConfirmationLabel="Confirmation Text" :confirmWithPassword="false">
                                    <x-slot:customButton>
                                        <div class="flex items-center gap-2">
                                            <div class="box-title">
                                                {{ $source->name }}
                                                @if ($application->source_id === $source->id)
                                                    <span class="text-xs">(current)</span>
                                                @endif
                                            </div>
                                            <div class="box-description">
                                                {{ $source->organization ?? 'Personal Account' }}
                                            </div>
                                        </div>
                                    </x-slot:customButton>
                                </x-modal-confirmation>
                            </div>
                        @empty
                            <div class="empty-state">No other sources found.</div>
                        @endforelse
                    </div>
                </div>
            @endcan
        @endif
    </form>
</div>

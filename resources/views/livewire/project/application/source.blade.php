<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Source</h2>
            @can('update', $application)
                <x-forms.button type="submit">{{ __('common.save') }}</x-forms.button>
            @endcan
            <div class="flex items-center gap-4 px-2">
                <a target="_blank" class="hover:no-underline flex items-center gap-1"
                    href="{{ $application?->gitBranchLocation }}">
                    {{ __('application.open_repository') }}
                    <x-external-link />
                </a>
                @if (data_get($application, 'source.is_public') === false)
                    <a target="_blank" class="hover:no-underline flex items-center gap-1"
                        href="{{ getInstallationPath($application->source) }}">
                        {{ __('application.open_git_app') }}
                        <x-external-link />
                    </a>
                @endif
                <a target="_blank" class="flex hover:no-underline items-center gap-1"
                    href="{{ $application?->gitCommits }}">
                    {{ __('application.open_commits_on_git') }}
                    <x-external-link />
                </a>
            </div>
        </div>
        <div class="pb-4">{{ __('application.code_source_desc') }}</div>

        <div class="flex flex-col gap-2">
            @if (!$privateKeyId)
                <div>{{ __('application.currently_connected_source') }} <span
                        class="font-bold text-warning">{{ data_get($application, 'source.name', __('application.no_source_connected')) }}</span>
                </div>
            @endif
            <div class="flex gap-2">
                <x-forms.input placeholder="coollabsio/coolify-example" id="gitRepository" label="{{ __('application.repository') }}"
                    canGate="update" :canResource="$application" />
                <x-forms.input placeholder="main" id="gitBranch" label="{{ __('application.branch') }}" canGate="update" :canResource="$application" />
            </div>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="HEAD" id="gitCommitSha" placeholder="HEAD" label="{{ __('application.commit_sha') }}"
                    canGate="update" :canResource="$application" />
            </div>
        </div>

        @if ($privateKeyId)
            <h3 class="pt-4">{{ __('application.deploy_key') }}</h3>
            <div class="py-2 pt-4">{{ __('application.currently_attached_private_key') }} <span
                    class="dark:text-warning">{{ $privateKeyName }}</span>
            </div>

            @can('update', $application)
                <h4 class="py-2 ">{{ __('application.select_another_private_key') }}</h4>
                <div class="flex flex-wrap gap-2">
                    @foreach ($privateKeys as $key)
                        <x-forms.button wire:click="setPrivateKey('{{ $key->id }}')">{{ $key->name }}
                        </x-forms.button>
                    @endforeach
                </div>
            @endcan
        @else
            @can('update', $application)
                <div class="pt-4">
                    <h3 class="pb-2">{{ __('application.change_git_source') }}</h3>
                    <div class="grid grid-cols-1 gap-2">
                        @forelse ($sources as $source)
                            <div wire:key="{{ $source->name }}">
                                <x-modal-confirmation title="{{ __('application.change_git_source') }}" :actions="[__('application.change_git_source_to') . ' ' . $source->name]" :buttonFullWidth="true"
                                    :isHighlightedButton="$application->source_id === $source->id" :disabled="$application->source_id === $source->id"
                                    submitAction="changeSource({{ $source->id }}, {{ $source->getMorphClass() }})"
                                    :confirmWithText="true" confirmationText="{{ __('application.change_git_source_confirmation') }}"
                                    confirmationLabel="{{ __('application.confirm_change_git_source_label') }}"
                                    shortConfirmationLabel="{{ __('application.confirmation_text') }}" :confirmWithPassword="false">
                                    <x-slot:customButton>
                                        <div class="flex items-center gap-2">
                                            <div class="box-title">
                                                {{ $source->name }}
                                                @if ($application->source_id === $source->id)
                                                    <span class="text-xs">{{ __('application.current') }}</span>
                                                @endif
                                            </div>
                                            <div class="box-description">
                                                {{ $source->organization ?? __('application.personal_account') }}
                                            </div>
                                        </div>
                                    </x-slot:customButton>
                                </x-modal-confirmation>
                            </div>
                        @empty
                            <div>{{ __('common.no_other_sources_found') }}</div>
                        @endforelse
                    </div>
                </div>
            @endcan
        @endif
    </form>
</div>

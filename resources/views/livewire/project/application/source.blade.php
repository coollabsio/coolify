<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Source</h2>
            <x-forms.button type="submit">Save</x-forms.button>
            <a target="_blank" class="hover:no-underline" href="{{ $application?->gitBranchLocation }}">
                <x-forms.button>
                    Open Repository
                    <x-external-link />
                </x-forms.button>
            </a>
            @if (data_get($application, 'source.is_public') === false)
                <a target="_blank" class="hover:no-underline" href="{{ getInstallationPath($application->source) }}">
                    <x-forms.button>
                        Open Git App
                        <x-external-link />
                    </x-forms.button>
                </a>
            @endif
            <a target="_blank" class="flex hover:no-underline" href="{{ $application?->gitCommits }}">
                <x-forms.button>Open Commits on Git
                    <x-external-link />
                </x-forms.button>
            </a>
        </div>
        <div class="pb-4">Code source of your application.</div>

        <div class="flex flex-col gap-2">
            @if ($application->source_id)
                <div>Currently connected source: <span
                        class="font-bold text-warning">{{ data_get($application, 'source.name', 'No source connected') }}</span>
                </div>
            @endif
            <div class="flex gap-2">
                <x-forms.input placeholder="coollabsio/coolify-example" id="gitRepository" label="Repository" />
                <x-forms.input placeholder="main" id="gitBranch" label="Branch" />
            </div>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="HEAD" id="gitCommitSha" placeholder="HEAD" label="Commit SHA" />
            </div>
        </div>

        {{-- Authentication Method Selection --}}
        @if (!$application->source_id)
            <h3 class="pt-4">Authentication Method</h3>
            <div class="pt-2 pb-4">
                <x-forms.select id="gitAuthType" label="Authentication Type" wire:change="changeAuthType">
                    <option value="deploy_key">SSH Deploy Key</option>
                    <option value="https_basic">HTTPS with Username/Password</option>
                </x-forms.select>
            </div>

            {{-- SSH Deploy Key Section --}}
            @if ($gitAuthType === 'deploy_key')
                @if ($privateKeyId)
                    <h4 class="pt-2">Deploy Key</h4>
                    <div class="py-2">Currently attached Private Key: <span
                            class="dark:text-warning">{{ $privateKeyName }}</span>
                    </div>

                    <h4 class="py-2">Select another Private Key</h4>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($privateKeys as $key)
                            <x-forms.button wire:click="setPrivateKey('{{ $key->id }}')">{{ $key->name }}
                            </x-forms.button>
                        @endforeach
                    </div>
                @else
                    <div class="pt-2">
                        <p class="text-warning">No SSH key is currently attached to this application.</p>
                        <p class="text-sm">Please select a private key from the list below:</p>
                        <div class="flex flex-wrap gap-2 pt-2">
                            @forelse ($privateKeys as $key)
                                <x-forms.button wire:click="setPrivateKey('{{ $key->id }}')">{{ $key->name }}
                                </x-forms.button>
                            @empty
                                <p>No private keys found. <a href="{{ route('security.private-key.index') }}" class="text-primary underline">Create a new private key</a></p>
                            @endforelse
                        </div>
                    </div>
                @endif
            @endif

            {{-- HTTPS Basic Auth Section --}}
            @if ($gitAuthType === 'https_basic')
                <h4 class="pt-2">HTTPS Credentials</h4>
                <div class="pt-2 pb-4">
                    <div class="p-4 mb-4 bg-warning/10 rounded-md">
                        <h5 class="font-bold mb-2">Security Notes:</h5>
                        <ul class="list-disc list-inside text-sm space-y-1">
                            <li>Your credentials will be encrypted before storage</li>
                            <li>We recommend using personal access tokens instead of passwords</li>
                            <li>For GitHub: Create a token at Settings → Developer settings → Personal access tokens</li>
                            <li>For GitLab: Create a token at User Settings → Access Tokens</li>
                            <li>Ensure your token has repository read permissions</li>
                        </ul>
                    </div>
                    
                    <div class="flex flex-col gap-4">
                        <x-forms.input 
                            id="gitBasicAuthUsername" 
                            label="Username" 
                            placeholder="Your Git username or 'oauth2' for GitLab"
                            helper="For GitHub, use your username. For GitLab, use 'oauth2' or your username." />
                        
                        <x-forms.input 
                            type="password"
                            id="gitBasicAuthPassword" 
                            label="Password / Personal Access Token" 
                            placeholder="Your password or personal access token"
                            helper="For GitHub/GitLab, we recommend using a personal access token instead of your password." />
                    </div>
                </div>
            @endif
        @else
            {{-- Show current source and allow changing --}}
            <div class="pt-4">
                <h3 class="pb-2">Change Git Source</h3>
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
                        <div>No other sources found</div>
                    @endforelse
                </div>
            </div>
        @endif
    </form>
</div>
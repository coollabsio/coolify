<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Source</h2>
            <x-forms.button type="submit">Save</x-forms.button>
            <a target="_blank" class="hover:no-underline" href="{{ $application?->gitBranchLocation }}">
                <x-forms.button>
                    <x-git-icon git="{{ $application->source?->getMorphClass() }}" />Open Repository on Git
                    <x-external-link />
                </x-forms.button>
            </a>
        </div>
        <div class="text-sm">Code source of your application.</div>
        <x-forms.input placeholder="coollabsio/coolify-example" id="application.git_repository" label="Repository" />
        <x-forms.input placeholder="main" id="application.git_branch" label="Branch" />
        <div class="flex items-end gap-2 w-96">
            <x-forms.input placeholder="HEAD" id="application.git_commit_sha" placeholder="HEAD" label="Commit SHA" />
            <a target="_blank" class="flex hover:no-underline" href="{{ $application?->gitCommits }}">
                <x-forms.button><svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                        stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M12 12m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" />
                        <path d="M12 3l0 6" />
                        <path d="M12 15l0 6" />
                    </svg>Open Commits on Git
                    <x-external-link />
                </x-forms.button>
            </a>
        </div>
        @if ($application->private_key_id)
            <h4 class="py-2 pt-4">Current Deploy Key: <span
                    class="text-warning">{{ $application->private_key->name }}</span></h4>

            <div class="py-2 text-sm">Select another Deploy Key</div>
            <div class="flex gap-2">
                @foreach ($private_keys as $key)
                    <x-forms.button wire:click.defer="setPrivateKey('{{ $key->id }}')">{{ $key->name }}
                    </x-forms.button>
                @endforeach
            </div>
        @endif
    </form>
</div>

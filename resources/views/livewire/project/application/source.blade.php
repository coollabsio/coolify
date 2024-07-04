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
                <a target="_blank" class="hover:no-underline" href="{{ get_installation_path($application->source) }}">
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
            <div class="flex gap-2">
                <x-forms.input placeholder="coollabsio/coolify-example" id="application.git_repository"
                    label="Repository" />
                <x-forms.input placeholder="main" id="application.git_branch" label="Branch" />
            </div>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="HEAD" id="application.git_commit_sha" placeholder="HEAD"
                    label="Commit SHA" />
            </div>
        </div>
        @if (data_get($application, 'private_key_id'))
            <h3 class="pt-4">Deploy Key</h3>
            <div class="py-2 pt-4">Currently attached Private Key: <span
                    class="dark:text-warning">{{ data_get($application, 'private_key.name') }}</span>
            </div>

            <h4 class="py-2 ">Select another Private Key</h4>
            <div class="flex flex-wrap gap-2">
                @foreach ($private_keys as $key)
                    <x-forms.button wire:click.defer="setPrivateKey('{{ $key->id }}')">{{ $key->name }}
                    </x-forms.button>
                @endforeach
            </div>
        @endif
    </form>
</div>

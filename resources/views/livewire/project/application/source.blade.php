<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2 class="pb-0">Source</h2>
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
        <div class="text-sm">Code source of your application.</div>
        <div class="py-4 ">
            <a target="_blank" class="hover:no-underline" href="{{ $application->gitCommits }}">
                <x-forms.button>Open Commits on Git
                    <x-external-link />
                </x-forms.button>
            </a>
            <a target="_blank" class="hover:no-underline" href="{{ $application->gitBranchLocation }}">
                <x-forms.button>Open Repository on Git
                    <x-external-link />
                </x-forms.button>
            </a>
        </div>
        <x-forms.input placeholder="coollabsio/coolify-example" id="application.git_repository" label="Repository" />
        <x-forms.input placeholder="main" id="application.git_branch" label=" Branch" />
        <x-forms.input placeholder="HEAD" id="application.git_commit_sha" placeholder="HEAD" label="Commit SHA" />

    </form>
</div>

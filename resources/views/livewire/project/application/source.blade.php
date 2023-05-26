<div>
    <form wire:submit.prevent='submit' class="flex flex-col gap-2">
        <div class="flex gap-4">
            <h2>Source</h2>
            <x-forms.button type="submit">Save</x-forms.button>
            <a target="_blank" href="{{ $application->gitCommits }}">
                Commits
                <x-external-link />
            </a>
            <a target="_blank" href="{{ $application->gitBranchLocation }}">
                Open Repository
                <x-external-link />
            </a>
        </div>
        <x-forms.input placeholder="coollabsio/coolify-example" id="application.git_repository" label="Repository" />
        <x-forms.input placeholder="main" id="application.git_branch" label=" Branch" />
        <x-forms.input placeholder="HEAD" id="application.git_commit_sha" placeholder="HEAD" label="Commit SHA" />
    </form>
</div>

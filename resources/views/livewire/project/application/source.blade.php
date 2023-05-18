<div>
    <h2>Source</h2>
    <div class="pb-8">{{ data_get($application, 'source.name') }}
        @if (data_get($application, 'source.is_public'))
            <span class="text-xs">public</span>
        @endif
    </div>
    <form wire:submit.prevent='submit' class="flex flex-col gap-2 w-max-fit">
        <x-inputs.input id="application.git_repository" label="Repository" />
        <x-inputs.input id="application.git_branch" label=" Branch" />
        <x-inputs.input id="application.git_commit_sha" placeholder="HEAD" label="Commit SHA" />
        <div>
            <x-inputs.button type="submit">Save</x-inputs.button>
            <a target="_blank" href="{{ $application->gitCommits }}">
                Commits
                <x-external-link />
            </a>
        </div>
    </form>

</div>

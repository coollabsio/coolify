<div>
    <form wire:submit.prevent='submit' class="flex flex-col gap-2">
        <div class="flex gap-4">
            <h2>Source</h2>
            <x-inputs.button type="submit">Save</x-inputs.button>
            <a target="_blank" href="{{ $application->gitCommits }}">
                Commits
                <x-external-link />
            </a>
        </div>
        {{-- <div>{{ data_get($application, 'source.name') }}
            @if (data_get($application, 'source.is_public'))
                <span class="text-xs">public</span>
            @endif
        </div> --}}
        <x-inputs.input placeholder="coollabsio/coolify-example" id="application.git_repository" label="Repository" />
        <x-inputs.input placeholder="main" id="application.git_branch" label=" Branch" />
        <x-inputs.input placeholder="HEAD" id="application.git_commit_sha" placeholder="HEAD" label="Commit SHA" />

    </form>

</div>

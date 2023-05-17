<div>
    <h3>Source</h3>
    <div class="pb-8">{{ data_get($application, 'source.name') }}
        @if (data_get($application, 'source.is_public'))
            <span class="text-xs">public</span>
        @endif
    </div>
    <form wire:submit.prevent='submit' class="flex flex-col gap-2 w-max-fit">
        <x-inputs.input id="application.git_repository" label="Repository" readonly />
        <x-inputs.input id="application.git_branch" label=" Branch" readonly />
        <x-inputs.input id="application.git_commit_sha" placeholder="HEAD" label="Commit SHA" />
        <div>
            <x-inputs.button isBold type="submit">Save</x-inputs.button>
            <a target="_blank" href="{{ $application->gitCommits }}">
                Commits <img class="inline-flex w-4 h-4" src="{{ asset('svgs/external-link.svg') }}">
            </a>
        </div>
    </form>

</div>

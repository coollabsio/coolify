<div>
    <p>Source Name: {{ data_get($application, 'source.name') }}</p>
    <p>Is Public Source: {{ data_get($application, 'source.is_public') }}</p>
    <div class="flex flex-col w-96">
        <x-inputs.input id="application.git_repository" label="Git Repository" readonly />
        <x-inputs.input id="application.git_branch" label="Git Branch" readonly />
        <form wire:submit.prevent='submit'>
            <x-inputs.input id="application.git_commit_sha" placeholder="HEAD" label="Git Commit SHA" />
            <x-inputs.button type="submit">Save</x-inputs.button>
        </form>
        <a target="_blank" href="{{ $application->gitCommits }}">
            <x-inputs.button>Commits ↗️</x-inputs.button>
        </a>
    </div>
</div>

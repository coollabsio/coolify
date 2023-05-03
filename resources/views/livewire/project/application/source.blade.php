<div>
    <p>Source Name: {{ data_get($application, 'source.name') }}</p>
    <p>Is Public Source: {{ data_get($application, 'source.is_public') }}</p>
    <div class="flex flex-col w-96">
        <x-inputs.input id="application.git_repository" label="Git Repository" readonly />
        <x-inputs.input id="application.git_branch" label="Git Branch" readonly />
        <x-inputs.input id="application.git_commit_sha" label="Git Commit SHA" readonly />
    </div>
</div>

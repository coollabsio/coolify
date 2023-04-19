<div>
    <p>{{ $application->source->name }}</p>
    <div class="flex-col flex w-96">
        <x-input name="application.git_repository" label="Git Repository" readonly />
        <x-input name="application.git_branch" label="Git Branch" readonly />
        <x-input name="application.git_commit_sha" label="Git Commit SHA" readonly />
    </div>
</div>

<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <x-input name="name" required="true" />
        <x-input name="fqdn" />
        <x-input name="git_repository" />
        <x-input name="git_branch" />
        <x-input name="git_commit_sha" />
        <button type="submit">
            Submit
        </button>
    </form>
</div>

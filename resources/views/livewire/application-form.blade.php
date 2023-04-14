<div>
    <form class="flex flex-col">
        <label>Name</label>
        <input wire:model="name" type="text" name="name" />
        <label>Fqdn</label>
        <input wire:model="fqdn" type="text" name="fqdn" />
        <label>Repository</label>
        <input wire:model="git_repository" type="text" name="git_repository" />
        <label>Branch</label>
        <input wire:model="git_branch" type="text" name="git_branch" />
        <label>Commit SHA</label>
        <input wire:model="git_commit_sha" type="text" name="git_commit_sha" />
        
    </form>
</div>

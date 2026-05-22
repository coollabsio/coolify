<div>
    <form class="flex flex-col gap-2" wire:submit='createGitLabApp'>
        <div class="flex gap-2">
            <h2>New GitLab App</h2>
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
        <div class="subtitle">Add a self-hosted or GitLab.com instance as a source for your applications.</div>
        <x-forms.input id="name" label="Name" required />
        <x-forms.input id="html_url" label="GitLab URL" required
            helper="For self-hosted GitLab, enter your instance URL (e.g., https://gitlab.example.com)." />
        <x-forms.input id="group_name" label="Group Name"
            helper="Optional. Comma-separated group names to filter repositories (e.g., myorg,myteam)." />
        @if (!isCloud())
            <x-forms.checkbox label="System Wide?" id="is_system_wide"
                helper="If checked, this GitLab App will be available for everyone in this Coolify instance." />
        @endif
    </form>
</div>

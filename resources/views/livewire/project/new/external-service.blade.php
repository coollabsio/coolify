<div>
    <h1>Deploy External Service</h1>
    <div class="subtitle">Deploy any service from a public Git repository with a docker-compose.yml file.</div>
    <form wire:submit.prevent="submit" class="flex flex-col gap-2 mt-4">
        <x-forms.input id="git_url" label="Git Repository URL" placeholder="https://github.com/coollabsio/coolify" />
        <x-forms.button type="submit">Fetch and Deploy</x-forms.button>
    </form>
    @if ($message)
        <div class="mt-4 text-error">{{ $message }}</div>
    @endif
</div>

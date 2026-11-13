<div class="flex flex-col gap-4">
    <div class="grid gap-4 sm:grid-cols-2">
        <x-forms.listbox id="connectSourceType" label="Source" live :options="[
            ['value' => 'public', 'label' => 'Public repository'],
            ['value' => 'github_app', 'label' => 'GitHub App', 'disabled' => empty($connectSources['github_app'] ?? [])],
            ['value' => 'gitea_app', 'label' => 'Gitea App', 'disabled' => empty($connectSources['gitea_app'] ?? [])],
            ['value' => 'deploy_key', 'label' => 'Deploy key', 'disabled' => empty($connectSources['deploy_key'] ?? [])],
        ]" />
        @if ($connectSourceType !== 'public')
            <x-forms.listbox id="connectSourceUuid" label="{{ ['github_app' => 'GitHub App', 'gitea_app' => 'Gitea App', 'deploy_key' => 'Deploy key'][$connectSourceType] ?? 'Source' }}"
                :options="collect($connectSources[$connectSourceType] ?? [])->map(fn ($item) => ['value' => $item['uuid'], 'label' => $item['name']])->all()" />
        @endif
    </div>
    <x-forms.input id="connectRepository" label="Repository" placeholder="https://github.com/acme/infra or acme/infra" required />
    <div class="grid gap-4 sm:grid-cols-3">
        <x-forms.input id="connectBranch" label="Branch" required />
        <x-forms.input id="connectFilePath" label="File" helper="Path in the repository." />
        <x-forms.listbox id="connectServerUuid" label="Read on server" helper="Coolify clones the repository on this server to read the file."
            :options="collect($connectServers)->map(fn ($server) => ['value' => $server['uuid'], 'label' => $server['name']])->all()" />
    </div>

    @if ($connectError)
        <p class="text-[13px] text-red-600 dark:text-red-400">{{ $connectError }}</p>
    @endif

    @if ($connectPlan)
        <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.08]">
            <p class="border-b border-neutral-200 px-3 py-2 text-[12px] font-medium dark:border-white/[0.08]">
                Plan of commit <code>{{ substr((string) ($connectPlan['commit'] ?? ''), 0, 7) }}</code>
            </p>
            @foreach ($connectPlan['errors'] ?? [] as $error)
                <p class="px-3 pt-2 text-[12px] text-red-600 dark:text-red-400"><code>{{ $error['path'] }}</code>: {{ $error['message'] }}</p>
            @endforeach
            <x-project-config.plan-groups :changes="$connectPlan['changes'] ?? []" compact />
        </div>
        <x-callout type="warning" title="Who can change this environment">
            Anyone who can push to {{ $connectBranch }} of {{ $connectRepository }} can change these resources and use the shared variables of this team.
        </x-callout>
    @endif

    <div class="flex justify-end gap-2">
        <x-forms.button wire:click="previewConnect" wire:target="previewConnect">Show plan</x-forms.button>
        @if ($connectPlan && empty($connectPlan['errors']))
            <x-forms.button isHighlighted wire:click="connect" wire:target="connect">Connect</x-forms.button>
        @endif
    </div>
</div>

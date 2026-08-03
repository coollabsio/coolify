@can('createAnyResource')
    <div class="w-full">
        <form class="flex w-full flex-col gap-4" wire:submit="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-forms.input id="name" label="Name" required />
                <x-forms.input id="network" label="Network" required />
            </div>
            <x-forms.listbox id="serverId" label="Server" required :live="true" :options="$servers
                ->map(
                    fn($server) => [
                        'value' => (string) $server->id,
                        'label' => $server->name,
                    ],
                )
                ->all()" />
            <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.07]">
                <x-forms.button type="submit"
                    class="bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                    Create destination
                </x-forms.button>
            </div>
        </form>
    </div>
@else
    <x-callout type="danger" title="Insufficient Permissions">
        You don't have permission to create new destinations. Please contact your team administrator for access.
    </x-callout>
@endcan

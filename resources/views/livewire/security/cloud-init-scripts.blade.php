<div>
    <x-security.navbar />
    <div class="form-section-title mb-6">
        <h2>Cloud-Init Scripts</h2>
        <div class="flex items-center gap-2">
            @can('create', App\Models\CloudInitScript::class)
                <x-modal-input buttonTitle="+ Add" title="New Cloud-Init Script">
                    <livewire:security.cloud-init-script-form />
                </x-modal-input>
            @endcan
        </div>
    </div>
    <div class="form-card max-w-none">
    <div class="pb-4 text-sm">Manage reusable cloud-init scripts for server initialization. Currently working only with <span class="text-red-500 font-bold">Hetzner's</span> integration.</div>

    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($scripts as $script)
            <div wire:key="script-{{ $script->id }}"
                class="flex flex-col gap-2 p-4 border border-neutral-200 dark:border-coolgray-300 rounded-lg bg-white dark:bg-coolgray-100 hover:no-underline">
                <div class="flex justify-between items-center">
                    <div class="flex-1">
                        <div class="font-bold dark:text-white">{{ $script->name }}</div>
                        <div class="text-xs text-neutral-500 dark:text-neutral-400">
                            Created {{ $script->created_at->diffForHumans() }}
                        </div>
                    </div>
                </div>

                <div class="flex gap-2 mt-2">
                    @can('update', $script)
                        <x-modal-input buttonTitle="Edit" title="Edit Cloud-Init Script" fullWidth>
                            <livewire:security.cloud-init-script-form :scriptId="$script->id"
                                wire:key="edit-{{ $script->id }}" />
                        </x-modal-input>
                    @endcan

                    @can('delete', $script)
                        <x-modal-confirmation title="Confirm Script Deletion?" isErrorButton buttonTitle="Delete"
                            submitAction="deleteScript({{ $script->id }})" :actions="[
                                'This cloud-init script will be permanently deleted.',
                                'This action cannot be undone.',
                            ]" confirmationText="{{ $script->name }}"
                            confirmationLabel="Please confirm the deletion by entering the script name below"
                            shortConfirmationLabel="Script Name" :confirmWithPassword="false"
                            step2ButtonText="Delete Script" />
                    @endcan
                </div>
            </div>
        @empty
            <div class="empty-state">No cloud-init scripts found. Create one to get started.</div>
        @endforelse
    </div>
    </div>
</div>

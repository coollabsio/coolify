<div>
    <x-security.navbar />
    <div class="flex items-center gap-2">
        <h2>Cloud-Init Scripts</h2>
        @can('create', App\Models\CloudInitScript::class)
            <x-modal-input buttonTitle="+ Add" title="New Cloud-Init Script">
                <livewire:security.cloud-init-script-form />
            </x-modal-input>
        @endcan
    </div>
    <div class="pb-4 text-sm">Manage reusable cloud-init scripts for server initialization with Hetzner, Vultr, and DigitalOcean integrations.</div>

    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($scripts as $script)
            @can('view', $script)
                <a wire:key="script-{{ $script->id }}" class="coolbox group"
                    href="{{ route('security.cloud-init-scripts.show', ['cloud_init_script_uuid' => $script->uuid]) }}" {{ wireNavigate() }}>
                    <div class="flex flex-col justify-center mx-6">
                        <div class="box-title">
                            {{ $script->name }}
                        </div>
                        <div class="box-description">
                            Cloud-init script
                        </div>
                    </div>
                </a>
            @endcan
        @empty
            <div class="text-neutral-500">No cloud-init scripts found. Create one to get started.</div>
        @endforelse
    </div>
</div>

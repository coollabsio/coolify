<div>
    <x-security.navbar />
    <div class="flex gap-2">
        <h2 class="pb-4">Docker Registries</h2>
        @can('create', App\Models\DockerRegistry::class)
            <x-modal-input buttonTitle="+ Add" title="New Docker Registry">
                <livewire:security.docker-registry.create />
            </x-modal-input>
        @endcan
    </div>
    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($registries as $registry)
            @can('view', $registry)
                <a class="coolbox group"
                    href="{{ route('security.docker-registry.show', ['registry_uuid' => data_get($registry, 'uuid')]) }}" {{ wireNavigate() }}>
                    <div class="flex flex-col justify-center mx-6">
                        <div class="box-title">
                            {{ data_get($registry, 'name') }}
                        </div>
                        <div class="box-description">
                            {{ $registry->description }}
                            <div class="text-xs text-helper">
                                {{ $registry->registry_url ?? 'docker.io' }}
                            </div>
                            @if ($registry->isInUse())
                                <span
                                    class="inline-flex items-center ml-2 px-2 py-0.5 rounded-sm text-xs font-medium bg-warning-400 text-black">In
                                    use</span>
                            @endif
                        </div>
                    </div>
                </a>
            @else
                <div class="coolbox opacity-60 !cursor-not-allowed hover:bg-transparent dark:hover:bg-transparent"
                    title="You don't have permission to view this registry">
                    <div class="flex flex-col justify-center mx-6">
                        <div class="box-title">
                            {{ data_get($registry, 'name') }}
                            <span
                                class="ml-2 inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-gray-400 dark:bg-gray-600 text-white">View
                                Only</span>
                        </div>
                        <div class="box-description">
                            {{ $registry->description }}
                            <div class="text-xs text-helper">
                                {{ $registry->registry_url ?? 'docker.io' }}
                            </div>
                        </div>
                    </div>
                </div>
            @endcan
        @empty
            <div>No docker registries found.</div>
        @endforelse
    </div>
</div>

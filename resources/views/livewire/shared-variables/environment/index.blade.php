<div>
    <x-slot:title>
        Environment Variables | Coolify
    </x-slot>
    <div class="flex gap-2">
        <h1>Environments</h1>
    </div>
    <div class="subtitle">List of your environments by projects.</div>
    <div x-data="{
        activeAccordion: '',
        setActiveAccordion(id) {
            this.activeAccordion = (this.activeAccordion == id) ? '' : id
        }
    }" class="flex flex-col gap-2">
        @forelse ($projects as $project)
            <div x-data="{ id: $id('accordion') }">
                <button @click="setActiveAccordion(id)"
                    class="box group flex flex-row justify-between w-full text-left cursor-pointer items-start lg:items-center"
                    type="button">
                    <div class="flex items-center gap-3 flex-1 mx-6">
                        <svg class="w-4 h-4 duration-200 ease-out opacity-60" :class="{ 'rotate-90': activeAccordion == id }"
                            viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="9 18 15 12 9 6"></polyline>
                        </svg>
                        <div class="flex flex-col flex-1">
                            <div class="box-title">{{ data_get($project, 'name') }}</div>
                            @if (data_get($project, 'description'))
                                <div class="box-description">{{ data_get($project, 'description') }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2 pr-4">
                        <span class="text-xs opacity-50">{{ $project->environments->count() }} {{ Str::plural('environment', $project->environments->count()) }}</span>
                    </div>
                </button>
                <div x-show="activeAccordion==id" x-collapse x-cloak class="flex flex-col gap-2 mt-2 ml-6 pl-4 border-l-2 border-coolgray-200/10">
                    @forelse ($project->environments as $environment)
                        <a class="box"
                            href="{{ route('shared-variables.environment.show', [
                                'project_uuid' => $project->uuid,
                                'environment_uuid' => $environment->uuid,
                            ]) }}">
                            <div class="flex flex-col justify-center flex-1 mx-6">
                                <div class="box-title">{{ $environment->name }}</div>
                                <div class="box-description">{{ $environment->description }}</div>
                            </div>
                        </a>
                    @empty
                        <p class="text-sm opacity-70 px-2">No environments found.</p>
                    @endforelse
                </div>
            </div>
        @empty
            <div>
                <div>No project found.</div>
            </div>
        @endforelse
    </div>
</div>
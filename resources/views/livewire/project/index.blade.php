<div>
    <x-slot:title>
        Projects | Coolify
    </x-slot>
    <div class="flex gap-2">
        <h1>Projects</h1>
        @can('createAnyResource')
            <x-modal-input buttonTitle="+ Add" title="New Project">
                <livewire:project.add-empty />
            </x-modal-input>
        @endcan
    </div>
    <div class="subtitle">All your projects are here.</div>
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2 -mt-1" x-data="{ projects: @js($projects) }">
        <template x-for="project in projects" :key="project.uuid">
            <div class="box group cursor-pointer" @click="$wire.navigateToProject(project.uuid)">
                <div class="flex flex-1 mx-6">
                    <div class="flex flex-col justify-center flex-1">
                        <div class="box-title" x-text="project.name"></div>
                        <div class="box-description">
                            <div x-text="project.description"></div>
                        </div>
                    </div>
                    <div class="relative z-10 flex items-center justify-center gap-4 text-xs font-bold"
                        x-show="project.canUpdate || project.canCreateResource">
                        <a class="hover:underline" wire:click.stop x-show="project.addResourceRoute"
                            :href="project.addResourceRoute">
                            + Add Resource
                        </a>
                        <a class="hover:underline" wire:click.stop x-show="project.canUpdate"
                            :href="`/project/${project.uuid}/edit`">
                            Settings
                        </a>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>

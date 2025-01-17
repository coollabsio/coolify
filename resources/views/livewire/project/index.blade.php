<div>
    <x-slot:title>
        Projects | Coolify
    </x-slot>
    <div class="flex gap-2">
        <h1>Projects</h1>
        <x-modal-input buttonTitle="+ Add" title="New Project">
            <livewire:project.add-empty />
        </x-modal-input>
    </div>
    <div class="subtitle">All your projects are here.</div>
    <div x-data="searchComponent()">
        <x-forms.input placeholder="Search for name, description..." x-model="search" id="null" />
        <div class="grid grid-cols-2 gap-4 pt-4">
            <template x-if="filteredProjects.length === 0">
                <div>No project found with the search term "<span x-text="search"></span>".</div>
            </template>

            <template x-for="project in filteredProjects" :key="project.uuid">
                <div class="box group cursor-pointer" @click="$wire.navigateToProject(project.uuid)">
                    <div class="flex flex-col justify-center flex-1 mx-6">
                        <div class="box-title" x-text="project.name"></div>
                        <div class="box-description">
                            <div x-text="project.description"></div>
                        </div>
                    </div>
                    <div class="flex items-center justify-center gap-2 pt-4 pb-2 mr-4 text-xs lg:py-0 lg:justify-normal">
                        <a class="mx-4 font-bold hover:underline" 
                           wire:navigate 
                           wire:click.stop
                           :href="`/project/${project.uuid}/edit`">
                            Settings
                        </a>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <script>
        function searchComponent() {
            return {
                search: '',
                get filteredProjects() {
                    const projects = @js($projects);
                    if (this.search === '') {
                        return projects;
                    }
                    const searchLower = this.search.toLowerCase();
                    return projects.filter(project => {
                        return (project.name?.toLowerCase().includes(searchLower) ||
                            project.description?.toLowerCase().includes(searchLower))
                    });
                }
            }
        }
    </script>
</div>

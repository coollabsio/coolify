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
            <template x-if="allFilteredItems.length === 0">
                <div>No project found with the search term "<span x-text="search"></span>".</div>
            </template>

            <template x-for="item in allFilteredItems" :key="item.uuid">
                <div class="box group" @click="gotoProject(item)">
                    <div class="flex flex-col justify-center flex-1 mx-6">
                        <div class="box-title" x-text="item.name"></div>
                        <div class="box-description ">
                            <div x-text="item.description"></div>
                        </div>
                    </div>
                    <div class="flex items-center justify-center gap-2 pt-4 pb-2 mr-4 text-xs lg:py-0 lg:justify-normal">
                        <a class="mx-4 font-bold hover:underline"
                           :href="item.settingsRoute">
                            Settings
                        </a>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <script>
        function sortFn(a, b) {
            return a.name.localeCompare(b.name)
        }

        function searchComponent() {
            return {
                search: '',
                projects: @js($projects),
                filterAndSort(items) {
                    if (this.search === '') {
                        return Object.values(items).sort(sortFn);
                    }
                    const searchLower = this.search.toLowerCase();
                    return Object.values(items).filter(item => {
                        return (item.name?.toLowerCase().includes(searchLower) ||
                            item.description?.toLowerCase().includes(searchLower))
                    }).sort(sortFn);
                },
                get allFilteredItems() {
                    return [
                        this.projects,
                    ].flatMap((items) => this.filterAndSort(items));
                }
            }
        }

        function gotoProject(item) {
            if (item.default_environment) {
                window.location.href = '/project/' + item.uuid + '/' + item.default_environment;
            } else {
                window.location.href = '/project/' + item.uuid;
            }
        }
    </script>
</div>

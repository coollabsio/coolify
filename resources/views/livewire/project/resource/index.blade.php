<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Resources | Coolify
    </x-slot>
    <div class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1>Resources</h1>
            @if ($environment->isEmpty())
                <a class="button"
                    href="{{ route('project.clone-me', ['project_uuid' => data_get($project, 'uuid'), 'environment_name' => request()->route('environment_name')]) }}">
                    Clone
                </a>
            @else
                <a href="{{ route('project.resource.create', ['project_uuid' => request()->route('project_uuid'), 'environment_name' => request()->route('environment_name')]) }}  "
                    class="button">+
                    New</a>
                <a class="button"
                    href="{{ route('project.clone-me', ['project_uuid' => data_get($project, 'uuid'), 'environment_name' => request()->route('environment_name')]) }}">
                    Clone
                </a>
            @endif
            <livewire:project.delete-environment :disabled="!$environment->isEmpty()" :environment_id="$environment->id" />
        </div>
        <nav class="flex pt-2 pb-6">
            <ol class="flex items-center">
                <li class="inline-flex items-center">
                    <a class="text-xs truncate lg:text-sm"
                        href="{{ route('project.show', ['project_uuid' => request()->route('project_uuid')]) }}">
                        {{ $project->name }}</a>
                </li>
                <li>
                    <div class="flex items-center">
                        <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold dark:text-warning" fill="currentColor"
                            viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd"
                                d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                clip-rule="evenodd"></path>
                        </svg>

                        <livewire:project.resource.environment-select :environments="$project->environments" />
                    </div>
                </li>
            </ol>
        </nav>
    </div>
    @if ($environment->isEmpty())
        <a href="{{ route('project.resource.create', ['project_uuid' => request()->route('project_uuid'), 'environment_name' => request()->route('environment_name')]) }} "
            class="items-center justify-center box">+ Add New Resource</a>
    @else
        <div x-data="searchComponent()">
            <x-forms.input autofocus placeholder="Search for name, fqdn..." x-model="search" id="null" />
            <div class="grid grid-cols-1 gap-4 pt-4 lg:grid-cols-2 xl:grid-cols-3">
                <template x-for="item in allFilteredItems" :key="item.uuid">
                    <span>
                        <a class="h-24 box group" :href="item.hrefLink">
                            <div class="flex flex-col w-full">
                                <div class="flex gap-2 px-4">
                                    <div class="pb-2 truncate box-title" x-text="item.name"></div>
                                    <div class="flex-1"></div>
                                    <template x-if="item.status.startsWith('running')">
                                        <div title="running" class="bg-success badge badge-absolute"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('exited')">
                                        <div title="exited" class="bg-error badge badge-absolute"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('restarting')">
                                        <div title="restarting" class="bg-warning badge badge-absolute"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('degraded')">
                                        <div title="degraded" class="bg-warning badge badge-absolute"></div>
                                    </template>
                                </div>
                                <div class="max-w-full px-4 truncate box-description" x-text="item.description"></div>
                                <div class="max-w-full px-4 truncate box-description" x-text="item.fqdn"></div>
                                <template x-if="item.server_status == false">
                                    <div class="px-4 text-xs font-bold text-error">The underlying server has problems
                                    </div>
                                </template>
                            </div>
                        </a>
                        <div
                            class="flex flex-wrap gap-1 pt-1 group-hover:dark:text-white group-hover:text-black group min-h-6">
                            <template x-for="tag in item.tags">
                                <div class="tag" @click.prevent="gotoTag(tag.name)" x-text="tag.name"></div>
                            </template>
                            <div class="add-tag" @click.prevent="goto(item)">Add tag</div>
                        </div>
                    </span>
                </template>
            </div>
        </div>
    @endif

</div>

<script>
    function sortFn(a, b) {
        return a.name.localeCompare(b.name)
    }

    function searchComponent() {
        return {
            search: '',
            applications: @js($applications),
            postgresqls: @js($postgresqls),
            redis: @js($redis),
            mongodbs: @js($mongodbs),
            mysqls: @js($mysqls),
            mariadbs: @js($mariadbs),
            keydbs: @js($keydbs),
            dragonflies: @js($dragonflies),
            clickhouses: @js($clickhouses),
            services: @js($services),
            gotoTag(tag) {
                window.location.href = '/tags/' + tag;
            },
            goto(item) {
                const hrefLink = item.hrefLink;
                window.location.href = `${hrefLink}#tags`;
            },
            filterAndSort(items) {
                if (this.search === '') {
                    return Object.values(items).sort(sortFn);
                }
                const searchLower = this.search.toLowerCase();
                return Object.values(items).filter(item => {
                    return (item.name?.toLowerCase().includes(searchLower) ||
                        item.fqdn?.toLowerCase().includes(searchLower) ||
                        item.description?.toLowerCase().includes(searchLower) ||
                        item.tags?.some(tag => tag.name.toLowerCase().includes(searchLower)));
                }).sort(sortFn);
            },
            get allFilteredItems() {
                return [
                    this.applications,
                    this.postgresqls,
                    this.redis,
                    this.mongodbs,
                    this.mysqls,
                    this.mariadbs,
                    this.keydbs,
                    this.dragonflies,
                    this.clickhouses,
                    this.services
                ].flatMap((items) => this.filterAndSort(items))
            }
        };
    }
</script>

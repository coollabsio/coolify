<div>
    <div class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1>Resources</h1>
            @if ($environment->isEmpty())
                <a class="font-normal text-white normal-case border-none rounded hover:no-underline btn btn-primary btn-sm no-animation"
                    href="{{ route('project.clone-me', ['project_uuid' => data_get($project, 'uuid'), 'environment_name' => request()->route('environment_name')]) }}">
                    Clone
                </a>
                <livewire:project.delete-environment :environment_id="$environment->id" />
            @else
                <a href="{{ route('project.resource.create', ['project_uuid' => request()->route('project_uuid'), 'environment_name' => request()->route('environment_name')]) }}  "
                    class="font-normal text-white normal-case border-none rounded hover:no-underline btn btn-primary btn-sm no-animation">+
                    New</a>
                <a class="font-normal text-white normal-case border-none rounded hover:no-underline btn btn-primary btn-sm no-animation"
                    href="{{ route('project.clone-me', ['project_uuid' => data_get($project, 'uuid'), 'environment_name' => request()->route('environment_name')]) }}">
                    Clone
                </a>
            @endif
        </div>
        <nav class="flex pt-2 pb-10">
            <ol class="flex items-center">
                <li class="inline-flex items-center">
                    <a class="text-xs truncate lg:text-sm"
                        href="{{ route('project.show', ['project_uuid' => request()->route('project_uuid')]) }}">
                        {{ $project->name }}</a>
                </li>
                <li>
                    <div class="flex items-center">
                        <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor"
                            viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd"
                                d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                clip-rule="evenodd"></path>
                        </svg>
                        <a class="text-xs truncate lg:text-sm"
                            href="{{ route('project.resource.index', ['environment_name' => request()->route('environment_name'), 'project_uuid' => request()->route('project_uuid')]) }}">{{ request()->route('environment_name') }}</a>
                    </div>
                </li>
            </ol>
        </nav>
    </div>
    @if ($environment->isEmpty())
        <a href="{{ route('project.resource.create', ['project_uuid' => request()->route('project_uuid'), 'environment_name' => request()->route('environment_name')]) }}  "
            class="items-center justify-center box">+ Add New Resource</a>
    @else
        <div x-data="searchComponent()">
            <x-forms.input placeholder="Search for name, fqdn..." class="w-full" x-model="search" />
            <div class="grid gap-2 pt-4 lg:grid-cols-2">
                <template x-for="item in filteredApplications" :key="item.id">
                    <a class="relative box group" :href="item.hrefLink">
                        <div class="flex flex-col mx-6">
                            <div class="pb-2 font-bold text-white" x-text="item.name"></div>
                            <div class="description" x-text="item.description"></div>
                            <div class="description" x-text="item.fqdn"></div>
                        </div>
                        <template x-if="item.status.startsWith('running')">
                            <div class="absolute bg-success -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('exited')">
                            <div class="absolute bg-error -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('restarting')">
                            <div class="absolute bg-warning -top-1 -left-1 badge badge-xs"></div>
                        </template>
                    </a>
                </template>
                <template x-for="item in filteredPostgresqls" :key="item.id">
                    <a class="relative box group" :href="item.hrefLink">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold text-white" x-text="item.name"></div>
                            <div class="description" x-text="item.description"></div>
                        </div>
                        <template x-if="item.status.startsWith('running')">
                            <div class="absolute bg-success -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('exited')">
                            <div class="absolute bg-error -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('restarting')">
                            <div class="absolute bg-warning -top-1 -left-1 badge badge-xs"></div>
                        </template>
                    </a>
                </template>
                <template x-for="item in filteredRedis" :key="item.id">
                    <a class="relative box group" :href="item.hrefLink">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold text-white" x-text="item.name"></div>
                            <div class="description" x-text="item.description"></div>
                        </div>
                        <template x-if="item.status.startsWith('running')">
                            <div class="absolute bg-success -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('exited')">
                            <div class="absolute bg-error -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('restarting')">
                            <div class="absolute bg-warning -top-1 -left-1 badge badge-xs"></div>
                        </template>
                    </a>
                </template>
                <template x-for="item in filteredMongodbs" :key="item.id">
                    <a class="relative box group" :href="item.hrefLink">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold text-white" x-text="item.name"></div>
                            <div class="description" x-text="item.description"></div>
                        </div>
                        <template x-if="item.status.startsWith('running')">
                            <div class="absolute bg-success -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('exited')">
                            <div class="absolute bg-error -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('restarting')">
                            <div class="absolute bg-warning -top-1 -left-1 badge badge-xs"></div>
                        </template>
                    </a>
                </template>
                <template x-for="item in filteredMysqls" :key="item.id">
                    <a class="relative box group" :href="item.hrefLink">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold text-white" x-text="item.name"></div>
                            <div class="description" x-text="item.description"></div>
                        </div>
                        <template x-if="item.status.startsWith('running')">
                            <div class="absolute bg-success -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('exited')">
                            <div class="absolute bg-error -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('restarting')">
                            <div class="absolute bg-warning -top-1 -left-1 badge badge-xs"></div>
                        </template>
                    </a>
                </template>
                <template x-for="item in filteredMariadbs" :key="item.id">
                    <a class="relative box group" :href="item.hrefLink">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold text-white" x-text="item.name"></div>
                            <div class="description" x-text="item.description"></div>
                        </div>
                        <template x-if="item.status.startsWith('running')">
                            <div class="absolute bg-success -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('exited')">
                            <div class="absolute bg-error -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('restarting')">
                            <div class="absolute bg-warning -top-1 -left-1 badge badge-xs"></div>
                        </template>
                    </a>
                </template>
                <template x-for="item in filteredServices" :key="item.id">
                    <a class="relative box group" :href="item.hrefLink">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold text-white" x-text="item.name"></div>
                            <div class="description" x-text="item.description"></div>
                        </div>
                        <template x-if="item.status.startsWith('running')">
                            <div class="absolute bg-success -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('exited')">
                            <div class="absolute bg-error -top-1 -left-1 badge badge-xs"></div>
                        </template>
                        <template x-if="item.status.startsWith('degraded')">
                            <div class="absolute bg-warning -top-1 -left-1 badge badge-xs"></div>
                        </template>
                    </a>
                </template>
            </div>
        </div>
    @endif

</div>

<script>
    function searchComponent() {
        return {
            search: '',
            applications: @js($applications),
            postgresqls: @js($postgresqls),
            redis: @js($redis),
            mongodbs: @js($mongodbs),
            mysqls: @js($mysqls),
            mariadbs: @js($mariadbs),
            services: @js($services),
            get filteredApplications() {
                if (this.search === '') {
                    return this.applications;
                }
                this.applications = Object.values(this.applications);
                return this.applications.filter(item => {
                    return item.name.toLowerCase().includes(this.search.toLowerCase()) ||
                        item.fqdn?.toLowerCase().includes(this.search.toLowerCase()) ||
                        item.description?.toLowerCase().includes(this.search.toLowerCase());
                });
            },
            get filteredPostgresqls() {
                if (this.search === '') {
                    return this.postgresqls;
                }
                this.postgresqls = Object.values(this.postgresqls);
                return this.postgresqls.filter(item => {
                    return item.name.toLowerCase().includes(this.search.toLowerCase()) ||
                        item.description?.toLowerCase().includes(this.search.toLowerCase());
                });
            },
            get filteredRedis() {
                if (this.search === '') {
                    return this.redis;
                }
                this.redis = Object.values(this.redis);
                return this.redis.filter(item => {
                    return item.name.toLowerCase().includes(this.search.toLowerCase()) ||
                        item.description?.toLowerCase().includes(this.search.toLowerCase());
                });
            },
            get filteredMongodbs() {
                if (this.search === '') {
                    return this.mongodbs;
                }
                this.mongodbs = Object.values(this.mongodbs);
                return this.mongodbs.filter(item => {
                    return item.name.toLowerCase().includes(this.search.toLowerCase()) ||
                        item.description?.toLowerCase().includes(this.search.toLowerCase());
                });
            },
            get filteredMysqls() {
                if (this.search === '') {
                    return this.mysqls;
                }
                this.mysqls = Object.values(this.mysqls);
                return this.mysqls.filter(item => {
                    return item.name.toLowerCase().includes(this.search.toLowerCase()) ||
                        item.description?.toLowerCase().includes(this.search.toLowerCase());
                });
            },
            get filteredMariadbs() {
                if (this.search === '') {
                    return this.mariadbs;
                }
                this.mariadbs = Object.values(this.mariadbs);
                return this.mariadbs.filter(item => {
                    return item.name.toLowerCase().includes(this.search.toLowerCase()) ||
                        item.description?.toLowerCase().includes(this.search.toLowerCase());
                });
            },
            get filteredServices() {
                if (this.search === '') {
                    return this.services;
                }
                this.services = Object.values(this.services);
                return this.services.filter(item => {
                    return item.name.toLowerCase().includes(this.search.toLowerCase()) ||
                        item.description?.toLowerCase().includes(this.search.toLowerCase());
                });
            },

        };
    }
</script>

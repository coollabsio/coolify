<div x-data="magicsearchbar">
    {{-- Main --}}
    <input x-ref="mainSearch" x-cloak x-show="!serverMenu && !destinationMenu && !projectMenu && !environmentMenu"
        x-model="mainSearch" class="w-96" x-on:click="checkMainMenu" x-on:click.outside="closeMainMenu"
        placeholder="🪄 Search for anything... magically..." />
    <div x-cloak x-show="mainMenu" class="absolute text-sm top-11 w-[25rem] bg-neutral-800">
        <template x-for="item in filteredItems" :key="item.name">
            <div x-on:click="await next('server',item.name, item.disabled ?? false)"
                :class="item.disabled && 'text-neutral-500 bg-neutral-900 hover:bg-neutral-900 cursor-not-allowed opacity-60'"
                class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                <span class="px-2 mr-1 text-xs bg-green-600 rounded" x-show="item.type === 'Add'"
                    x-text="item.type"></span>
                <span class="px-2 mr-1 text-xs bg-purple-600 rounded" x-show="item.type === 'Jump'"
                    x-text="item.type"></span>
                <span x-text="item.name"></span>
            </div>
        </template>
    </div>
    {{-- Servers --}}
    <div x-cloak x-show="serverMenu" x-on:click.outside="closeServerMenu">
        <input x-ref="serverSearch" x-model="serverSearch" class="w-96" placeholder="Select a server" />
        <div class="absolute text-sm top-11 w-[25rem] bg-neutral-800">
            <template x-for="server in filteredServers" :key="server.name ?? server">
                <div x-on:click="await next('destination',server.uuid)"
                    class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                    <span class="px-2 mr-1 text-xs bg-purple-600 rounded">Server</span>
                    <span x-text="server.name"></span>
                </div>
            </template>
        </div>
    </div>
    {{-- Destinations --}}
    <div x-cloak x-show="destinationMenu" x-on:click.outside="closeDestinationMenu">
        <input x-ref="destinationSearch" x-model="destinationSearch" class="w-96"
            placeholder="Select a destination" />
        <div class="absolute text-sm top-11 w-[25rem] bg-neutral-800">
            <template x-for="destination in filteredDestinations" :key="destination.name ?? destination">
                <div x-on:click="await next('project',destination.uuid)"
                    class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                    <span class="px-2 mr-1 text-xs bg-purple-700 rounded">Destination</span>
                    <span x-text="destination.name"></span>
                </div>
            </template>
        </div>
    </div>
    {{-- Projects --}}
    <div x-cloak x-show="projectMenu" x-on:click.outside="closeProjectMenu">
        <input x-ref="projectSearch" x-model="projectSearch" class="w-96" placeholder="Type your project name..." />
        <div class="absolute text-sm top-11 w-[25rem] bg-neutral-800">
            <div x-on:click="await newProject" class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                <span>New Project</span>
                <span x-text="projectSearch"></span>
            </div>
            <template x-for="project in filteredProjects" :key="project.name ?? project">
                <div x-on:click="await next('environment',project.uuid)"
                    class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                    <span class="px-2 mr-1 text-xs bg-purple-700 rounded">Project</span>
                    <span x-text="project.name"></span>
                </div>
            </template>
        </div>
    </div>
    {{-- Environments --}}
    <div x-cloak x-show="environmentMenu" x-on:click.outside="closeEnvironmentMenu">
        <input x-ref="environmentSearch" x-model="environmentSearch" class="w-96"
            placeholder="Select a environment" />
        <div class="absolute text-sm top-11 w-[25rem] bg-neutral-800">
            <div x-on:click="await newEnvironment" class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                <span>New Environment</span>
                <span x-text="environmentSearch"></span>
            </div>
            <template x-for="environment in filteredEnvironments" :key="environment.name ?? environment">
                <div x-on:click="await next('jump',environment.name)"
                    class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                    <span class="px-2 mr-1 text-xs bg-purple-700 rounded">Env</span>
                    <span x-text="environment.name"></span>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('magicsearchbar', () => ({
            init() {
                this.$watch('mainMenu', (value) => {
                    if (value) this.$refs.mainSearch.focus()
                })
                this.$watch('serverMenu', (value) => {
                    this.$nextTick(() => {
                        this.$refs.serverSearch.focus()
                    })
                })
                this.$watch('destinationMenu', (value) => {
                    this.$nextTick(() => {
                        this.$refs.destinationSearch.focus()
                    })
                })
                this.$watch('projectMenu', (value) => {
                    this.$nextTick(() => {
                        this.$refs.projectSearch.focus()
                    })
                })
                this.$watch('environmentMenu', (value) => {
                    this.$nextTick(() => {
                        this.$refs.environmentSearch.focus()
                    })
                })
            },
            mainMenu: false,
            serverMenu: false,
            destinationMenu: false,
            projectMenu: false,
            environmentMenu: false,

            mainSearch: '',
            serverSearch: '',
            destinationSearch: '',
            projectSearch: '',
            environmentSearch: '',

            selectedAction: '',
            selectedServer: '',
            selectedDestination: '',
            selectedProject: '',
            selectedEnvironment: '',

            servers: ['Loading...'],
            destinations: ['Loading...'],
            projects: ['Loading...'],
            environments: ['Loading...'],
            items: [{
                    name: 'Public Repository',
                    type: 'Add',
                    tags: 'application,public,repository',
                },
                {
                    name: 'Private Repository (with GitHub App)',
                    type: 'Add',
                    tags: 'application,private,repository',
                },
                {
                    name: 'Private Repository (with Deploy Key)',
                    type: 'Add',
                    tags: 'application,private,repository',
                },
                {
                    name: 'Database',
                    type: 'Add',
                    tags: 'data,database,mysql,postgres,sql,sqlite,redis,mongodb,maria,percona',
                    disabled: true,
                },
                {
                    name: 'Servers',
                    type: 'Jump',
                }
            ],
            checkMainMenu() {
                if (this.serverMenu) return
                this.mainMenu = true
            },
            closeMainMenu() {
                this.mainMenu = false
                this.mainSearch = ''
            },
            closeServerMenu() {
                this.serverMenu = false
                this.serverSearch = ''
            },
            closeDestinationMenu() {
                this.destinationMenu = false
                this.destinationSearch = ''
            },
            closeProjectMenu() {
                this.projectMenu = false
                this.projectSearch = ''
            },
            closeEnvironmentMenu() {
                this.environmentMenu = false
                this.environmentSearch = ''
            },
            filteredItems() {
                if (this.mainSearch === '') return this.items
                return this.items.filter(item => {
                    return item.name.toLowerCase().includes(this.mainSearch.toLowerCase())
                })
            },
            filteredServers() {
                if (this.serverSearch === '') return this.servers
                return this.servers.filter(server => {
                    return server.name.toLowerCase().includes(this.serverSearch
                        .toLowerCase())
                })
            },
            filteredDestinations() {
                if (this.destinationSearch === '') return this.destinations
                return this.destinations.filter(destination => {
                    return destination.name.toLowerCase().includes(this.destinationSearch
                        .toLowerCase())
                })
            },
            filteredProjects() {
                if (this.projectSearch === '') return this.projects
                return this.projects.filter(project => {
                    return project.name.toLowerCase().includes(this.projectSearch
                        .toLowerCase())
                })
            },
            filteredEnvironments() {
                if (this.environmentSearch === '') return this.environments
                return this.environments.filter(environment => {
                    return environment.name.toLowerCase().includes(this.environmentSearch
                        .toLowerCase())
                })
            },
            async newProject() {
                const response = await fetch('/magic?server=' + this.selectedServer +
                    '&destination=' + this.selectedDestination +
                    '&project=new&name=' + this.projectSearch);
                if (response.ok) {
                    const {
                        project_uuid
                    } = await response.json();
                    this.next('environment', project_uuid)
                    this.next('jump', 'production')
                }
            },
            async newEnvironment() {
                const response = await fetch('/magic?server=' + this.selectedServer +
                    '&destination=' + this.selectedDestination +
                    '&project=' + this.selectedProject + '&environment=new&name=' + this
                    .environmentSearch);
                if (response.ok) {
                    this.next('jump', this.environmentSearch)
                }
            },
            async next(action, id, isDisabled) {
                if (isDisabled) return
                let response = null
                switch (action) {
                    case 'server':
                        this.mainMenu = false
                        this.serverMenu = true
                        this.items.find((item, index) => {
                            if (item.name.toLowerCase() === id
                                .toLowerCase()) {
                                return this.selectedAction = index
                            }
                        })
                        response = await fetch('/magic?servers=true');
                        if (response.ok) {
                            const {
                                servers
                            } = await response.json();
                            this.servers = servers;
                        }
                        break
                    case 'destination':
                        if (this.items[this.selectedAction].type === "Jump") {
                            return window.location = '/server/' + id
                        }
                        this.mainMenu = false
                        this.serverMenu = false
                        this.destinationMenu = true
                        this.selectedServer = id

                        response = await fetch('/magic?server=' + this
                            .selectedServer +
                            '&destinations=true');
                        if (response.ok) {
                            const {
                                destinations
                            } = await response.json();
                            this.destinations = destinations;
                        }
                        break
                    case 'project':
                        this.mainMenu = false
                        this.serverMenu = false
                        this.destinationMenu = false
                        this.projectMenu = true
                        this.selectedDestination = id
                        response = await fetch('/magic?server=' + this
                            .selectedServer +
                            '&destination=' + this.selectedDestination +
                            '&projects=true');
                        if (response.ok) {
                            const {
                                projects
                            } = await response.json();
                            this.projects = projects;
                        }
                        break
                    case 'environment':
                        this.mainMenu = false
                        this.serverMenu = false
                        this.destinationMenu = false
                        this.projectMenu = false
                        this.environmentMenu = true
                        this.selectedProject = id


                        response = await fetch('/magic?server=' + this
                            .selectedServer +
                            '&destination=' + this.selectedDestination +
                            '&project=' + this
                            .selectedProject + '&environments=true');
                        if (response.ok) {
                            const {
                                environments
                            } = await response.json();
                            this.environments = environments;
                        }
                        break
                    case 'jump':
                        this.mainMenu = false
                        this.serverMenu = false
                        this.destinationMenu = false
                        this.projectMenu = false
                        this.environmentMenu = false
                        this.selectedEnvironment = id

                        if (this.selectedAction === 0) {
                            window.location =
                                `/project/${this.selectedProject}/${this.selectedEnvironment}/new?type=public&destination=${this.selectedDestination}`
                        } else if (this.selectedAction === 1) {
                            window.location =
                                `/project/${this.selectedProject}/${this.selectedEnvironment}/new?type=private-gh-app&destination=${this.selectedDestination}`
                        } else if (this.selectedAction === 2) {
                            window.location =
                                `/project/${this.selectedProject}/${this.selectedEnvironment}/new?type=private-deploy-key&destination=${this.selectedDestination}`
                        } else if (this.selectedAction === 3) {
                            console.log('new Database')
                        }

                        break
                }
            }
        }))
    })
</script>

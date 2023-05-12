<div x-data="magicsearchbar">
    {{-- Main --}}
    <template x-cloak x-if="!serverMenu && !destinationMenu && !projectMenu && !environmentMenu">
        <div>
            <input x-ref="search" x-model="search" class="w-96" x-on:click="checkMainMenu" x-on:click.outside="closeMenus"
                placeholder="🪄 Search for anything... magically..." x-on:keyup.down="focusNext(items.length)"
                x-on:keyup.up="focusPrev(items.length)"
                x-on:keyup.enter="await set('server',filteredItems()[focusedIndex].name)" />
            <div x-cloak x-show="mainMenu" class="absolute text-sm top-11 w-[25rem] bg-neutral-800">
                <template x-for="(item,index) in filteredItems" :key="item.name">
                    <div x-on:click="await set('server',item.name)" :class="focusedIndex === index && 'bg-neutral-700'"
                        :class="item.disabled &&
                            'text-neutral-500 bg-neutral-900 hover:bg-neutral-900 cursor-not-allowed opacity-60'"
                        class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                        <span class="px-2 mr-1 text-xs bg-green-600 rounded" x-show="item.type === 'Add'"
                            x-text="item.type"></span>
                        <span class="px-2 mr-1 text-xs bg-purple-600 rounded" x-show="item.type === 'Jump'"
                            x-text="item.type"></span>
                        <span x-text="item.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
    {{-- Servers --}}
    <template x-cloak x-if="serverMenu">
        <div x-on:click.outside="closeMenus">
            <input x-ref="search" x-model="search" class="w-96" placeholder="Select a server..."
                x-on:keyup.down="focusNext(servers.length)" x-on:keyup.up="focusPrev(servers.length)"
                x-on:keyup.enter="await set('destination',filteredServers()[focusedIndex].uuid)" />
            <div class="absolute text-sm top-11 w-[25rem] bg-neutral-800">
                <template x-for="(server,index) in filteredServers" :key="server.name ?? server">
                    <div x-on:click="await set('destination',server.uuid)"
                        :class="focusedIndex === index && 'bg-neutral-700'"
                        class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                        <span class="px-2 mr-1 text-xs bg-purple-600 rounded">Server</span>
                        <span x-text="server.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
    {{-- Destinations --}}
    <template x-cloak x-if="destinationMenu">
        <div x-on:click.outside="closeMenus">
            <input x-ref="search" x-model="search" class="w-96" placeholder="Select a destination..."
                x-on:keyup.down="focusNext(destinations.length)" x-on:keyup.up="focusPrev(destinations.length)"
                x-on:keyup.enter="await set('project',filteredDestinations()[focusedIndex].uuid)" />
            <div class="absolute text-sm top-11 w-[25rem] bg-neutral-800">
                <template x-for="(destination,index) in filteredDestinations" :key="destination.name ?? destination">
                    <div x-on:click="await set('project',destination.uuid)"
                        :class="focusedIndex === index && 'bg-neutral-700'"
                        class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                        <span class="px-2 mr-1 text-xs bg-purple-700 rounded">Destination</span>
                        <span x-text="destination.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
    {{-- Projects --}}
    <template x-cloak x-if="projectMenu">
        <div x-on:click.outside="closeMenus">
            <input x-ref="search" x-model="search" class="w-96" placeholder="Type your project name..."
                x-on:keyup.down="focusNext(projects.length + 1)" x-on:keyup.up="focusPrev(projects.length + 1)"
                x-on:keyup.enter="await set('environment',filteredProjects()[focusedIndex - 1]?.uuid)" />
            <div class="absolute text-sm top-11 w-[25rem] bg-neutral-800">
                <div x-on:click="await newProject" class="py-2 pl-4 cursor-pointer hover:bg-neutral-700"
                    :class="focusedIndex === 0 && 'bg-neutral-700'">
                    <span>New Project</span>
                    <span x-text="search"></span>
                </div>
                <template x-for="(project,index) in filteredProjects" :key="project.name ?? project">
                    <div x-on:click="await set('environment',project.uuid)"
                        :class="focusedIndex === index + 1 && 'bg-neutral-700'"
                        class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                        <span class="px-2 mr-1 text-xs bg-purple-700 rounded">Project</span>
                        <span x-text="project.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
    {{-- Environments --}}
    <template x-cloak x-if="environmentMenu">
        <div x-on:click.outside="closeMenus">
            <input x-ref="search" x-model="search" class="w-96" placeholder="Select a environment..."
                x-on:keyup.down="focusNext(environments.length + 1)" x-on:keyup.up="focusPrev(environments.length + 1)"
                x-on:keyup.enter="await set('jump',filteredEnvironments()[focusedIndex - 1]?.name)" />
            <div class="absolute text-sm top-11 w-[25rem] bg-neutral-800">
                <div x-on:click="await newEnvironment" class="py-2 pl-4 cursor-pointer hover:bg-neutral-700"
                    :class="focusedIndex === 0 && 'bg-neutral-700'">
                    <span>New Environment</span>
                    <span x-text="search"></span>
                </div>
                <template x-for="(environment,index) in filteredEnvironments" :key="environment.name ?? environment">
                    <div x-on:click="await set('jump',environment.name)"
                        :class="focusedIndex === index + 1 && 'bg-neutral-700'"
                        class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                        <span class="px-2 mr-1 text-xs bg-purple-700 rounded">Env</span>
                        <span x-text="environment.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('magicsearchbar', () => ({
            focus() {
                if (this.$refs.search) this.$refs.search.focus()
            },
            init() {
                this.$watch('search', () => {
                    this.focusedIndex = ""
                })
                this.$watch('mainMenu', () => {
                    this.focus()
                })
                this.$watch('serverMenu', () => {
                    this.focus()
                })
                this.$watch('destinationMenu', () => {
                    this.focus()
                })
                this.$watch('projectMenu', () => {
                    this.focus()
                })
                this.$watch('environmentMenu', () => {
                    this.focus()
                })
            },
            mainMenu: false,
            serverMenu: false,
            destinationMenu: false,
            projectMenu: false,
            environmentMenu: false,
            search: '',

            selectedAction: '',
            selectedServer: '',
            selectedDestination: '',
            selectedProject: '',
            selectedEnvironment: '',

            servers: ['Loading...'],
            destinations: ['Loading...'],
            projects: ['Loading...'],
            environments: ['Loading...'],

            focusedIndex: "",
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
            focusPrev(maxLength) {
                if (this.focusedIndex === "") {
                    this.focusedIndex = maxLength - 1
                } else {
                    if (this.focusedIndex > 0) {
                        this.focusedIndex = this.focusedIndex - 1
                    }
                }
            },
            focusNext(maxLength) {
                if (this.focusedIndex === "") {
                    this.focusedIndex = 0
                } else {
                    if (maxLength > this.focusedIndex + 1) {
                        this.focusedIndex = this.focusedIndex + 1
                    }
                }
            },
            closeMenus() {
                this.focusedIndex = ''
                this.mainMenu = false
                this.serverMenu = false
                this.destinationMenu = false
                this.projectMenu = false
                this.environmentMenu = false
                this.search = ''
            },
            checkMainMenu() {
                if (this.serverMenu) return
                this.mainMenu = true
            },
            filteredItems() {
                if (this.search === '') return this.items
                return this.items.filter(item => {
                    return item.name.toLowerCase().includes(this.search.toLowerCase())
                })
            },
            filteredServers() {
                if (this.search === '') return this.servers
                return this.servers.filter(server => {
                    return server.name.toLowerCase().includes(this.search
                        .toLowerCase())
                })
            },
            filteredDestinations() {
                if (this.search === '') return this.destinations
                return this.destinations.filter(destination => {
                    return destination.name.toLowerCase().includes(this.search
                        .toLowerCase())
                })
            },
            filteredProjects() {
                if (this.search === '') return this.projects
                return this.projects.filter(project => {
                    return project.name.toLowerCase().includes(this.search
                        .toLowerCase())
                })
            },
            filteredEnvironments() {
                if (this.search === '') return this.environments
                return this.environments.filter(environment => {
                    return environment.name.toLowerCase().includes(this.search
                        .toLowerCase())
                })
            },
            async newProject() {
                const response = await fetch('/magic?server=' + this.selectedServer +
                    '&destination=' + this.selectedDestination +
                    '&project=new&name=' + this.search);
                if (response.ok) {
                    const {
                        project_uuid
                    } = await response.json();
                    console.log(project_uuid);
                    this.set('environment', project_uuid)
                    this.set('jump', 'production')
                }
            },
            async newEnvironment() {
                console.log('new environment')
                const response = await fetch('/magic?server=' + this.selectedServer +
                    '&destination=' + this.selectedDestination +
                    '&project=' + this.selectedProject + '&environment=new&name=' + this
                    .search);
                if (response.ok) {
                    const {
                        environment_name
                    } = await response.json();
                    this.set('jump', environment_name)
                }
            },
            async set(action, id) {
                let response = null
                switch (action) {
                    case 'server':
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
                        this.closeMenus()
                        this.serverMenu = true
                        break
                    case 'destination':
                        this.selectedServer = id
                        if (this.items[this.selectedAction].type === "Jump") {
                            return window.location = '/server/' + id
                        }
                        response = await fetch('/magic?server=' + this
                            .selectedServer +
                            '&destinations=true');
                        if (response.ok) {
                            const {
                                destinations
                            } = await response.json();
                            this.destinations = destinations;
                        }
                        this.closeMenus()
                        this.destinationMenu = true
                        break
                    case 'project':
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
                        this.closeMenus()
                        this.projectMenu = true
                        break
                    case 'environment':
                        if (this.focusedIndex === 0) {
                            this.focusedIndex = ''
                            return await this.newProject()
                        }

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

                        this.closeMenus()
                        this.environmentMenu = true
                        break
                    case 'jump':
                        if (this.focusedIndex === 0) {
                            this.focusedIndex = ''
                            return await this.newEnvironment()
                        }
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
                        this.closeMenus()
                        break
                }
            }
        }))
    })
</script>

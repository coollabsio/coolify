<div x-data="magicsearchbar" @slash.window="mainMenu = true">
    {{-- Main --}}
    <template x-cloak x-if="isMainMenu">
        <div>
            <div class="main-menu">
                <input class="magic-input" x-ref="search" x-model="search" x-on:click="checkMainMenu"
                    x-on:click.outside="closeMenus" placeholder="Search, jump or create... magically... 🪄"
                    x-on:keyup.escape="clearSearch" x-on:keydown.down="focusNext(items.length)"
                    x-on:keydown.up="focusPrev(items.length)"
                    x-on:keyup.enter="focusedIndex !== '' && await set(filteredItems()[focusedIndex]?.next ?? 'server',filteredItems()[focusedIndex]?.name)" />
            </div>
            <div x-show="mainMenu" class="magic-items">
                <template x-for="(item,index) in filteredItems" :key="item.name">
                    <div x-on:click="await set(item.next ?? 'server',item.name)"
                        :class="focusedIndex === index && 'magic-item-focused'" class="magic-item">
                        <span class="px-2 mr-1 text-xs text-white bg-green-600 rounded" x-show="item.type === 'App'"
                            x-text="item.type"></span>
                        <span class="px-2 mr-1 text-xs text-white bg-indigo-600 rounded" x-show="item.type === 'Add'"
                            x-text="item.type"></span>
                        <span class="px-2 mr-1 text-xs text-white bg-purple-600 rounded" x-show="item.type === 'Jump'"
                            x-text="item.type"></span>
                        <span class="px-2 mr-1 text-xs text-white bg-blue-600 rounded" x-show="item.type === 'New'"
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
            <input class="magic-input" x-ref="search" x-model="search" placeholder="Select a server..."
                x-on:keydown.down="focusNext(servers.length)" x-on:keydown.up="focusPrev(servers.length)"
                x-on:keyup.enter="focusedIndex !== '' && await set('destination',filteredServers()[focusedIndex].uuid)" />
            <div class="magic-items">
                <template x-if="servers.length === 0">
                    <div class="magic-item" x-on:click="set('newServer')">
                        <span>No server found. Click here to add a new one!</span>
                    </div>
                </template>
                <template x-for="(server,index) in filteredServers" :key="server.name ?? server">
                    <div x-on:click="await set('destination',server.uuid)"
                        :class="focusedIndex === index && 'magic-item-focused'" class="magic-item">
                        <span class="px-2 mr-1 text-xs text-white bg-purple-600 rounded">Server</span>
                        <span x-text="server.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
    {{-- Destinations --}}
    <template x-cloak x-if="destinationMenu">
        <div x-on:click.outside="closeMenus">
            <input class="magic-input" x-ref="search" x-model="search" placeholder="Select a destination..."
                x-on:keydown.down="focusNext(destinations.length)" x-on:keydown.up="focusPrev(destinations.length)"
                x-on:keyup.escape="closeMenus"
                x-on:keyup.enter="focusedIndex !== '' && await set('project',filteredDestinations()[focusedIndex].uuid)" />
            <div class="magic-items">
                <template x-if="destinations.length === 0">
                    <div class="magic-item" x-on:click="set('newDestination')">
                        <span>No destination found. Click here to add a new one!</span>
                    </div>
                </template>
                <template x-for="(destination,index) in filteredDestinations" :key="destination.name ?? destination">
                    <div x-on:click="await set('project',destination.uuid)"
                        :class="focusedIndex === index && 'magic-item-focused'" class="magic-item">
                        <span class="px-2 mr-1 text-xs text-white bg-purple-700 rounded">Destination</span>
                        <span x-text="destination.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
    {{-- Project --}}
    <template x-cloak x-if="projectMenu">
        <div x-on:click.outside="closeMenus">
            <input class="magic-input" x-ref="search" x-model="search" placeholder="Type your project name..."
                x-on:keydown.down="focusNext(projects.length + 1)" x-on:keydown.up="focusPrev(projects.length + 1)"
                x-on:keyup.escape="closeMenus"
                x-on:keyup.enter="focusedIndex !== '' && await set('environment',filteredProjects()[focusedIndex - 1]?.uuid)" />
            <div class="magic-items">
                <div x-on:click="await newProject" class="magic-item"
                    :class="focusedIndex === 0 && 'magic-item-focused'">
                    <span>New Project</span>
                    <span x-text="search"></span>
                </div>
                <template x-for="(project,index) in filteredProjects" :key="project.name ?? project">
                    <div x-on:click="await set('environment',project.uuid)"
                        :class="focusedIndex === index + 1 && 'magic-item-focused'" class="magic-item">
                        <span class="px-2 mr-1 text-xs text-white bg-purple-700 rounded">Project</span>
                        <span x-text="project.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
    {{-- Environments --}}
    <template x-cloak x-if="environmentMenu">
        <div x-on:click.outside="closeMenus">
            <input class="magic-input" x-ref="search" x-model="search" placeholder="Select a environment..."
                x-on:keydown.down="focusNext(environments.length + 1)"
                x-on:keydown.up="focusPrev(environments.length + 1)" x-on:keyup.escape="closeMenus"
                x-on:keyup.enter="focusedIndex !== '' && await set('jump',filteredEnvironments()[focusedIndex - 1]?.name)" />
            <div class="magic-items">
                <div x-on:click="await newEnvironment" class="magic-item"
                    :class="focusedIndex === 0 && 'magic-item-focused'">
                    <span>New Environment</span>
                    <span x-text="search"></span>
                </div>
                <template x-for="(environment,index) in filteredEnvironments" :key="environment.name ?? environment">
                    <div x-on:click="await set('jump',environment.name)"
                        :class="focusedIndex === index + 1 && 'magic-item-focused'" class="magic-item">
                        <span class="px-2 mr-1 text-xs text-white bg-purple-700 rounded">Env</span>
                        <span x-text="environment.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
    {{-- Projects --}}
    <template x-cloak x-if="projectsMenu">
        <div x-on:click.outside="closeMenus">
            <input x-ref="search" x-model="search" class="magic-input" placeholder="Select a project..."
                x-on:keyup.escape="closeMenus" x-on:keydown.down="focusNext(projects.length)"
                x-on:keydown.up="focusPrev(projects.length)"
                x-on:keyup.enter="focusedIndex !== '' && await set('jumpToProject',filteredProjects()[focusedIndex]?.uuid)" />
            <div class="magic-items">
                <template x-if="projects.length === 0">
                    <div class="magic-item hover:bg-neutral-800">
                        <span>No projects found.</span>
                    </div>
                </template>
                <template x-for="(project,index) in filteredProjects" :key="project.name ?? project">
                    <div x-on:click="await set('jumpToProject',project.uuid)"
                        :class="focusedIndex === index && 'magic-item-focused'"
                        class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                        <span class="px-2 mr-1 text-xs text-white bg-purple-700 rounded">Jump</span>
                        <span x-text="project.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
    {{-- Destinations --}}
    <template x-cloak x-if="destinationsMenu">
        <div x-on:click.outside="closeMenus">
            <input x-ref="search" x-model="search" class="magic-input" placeholder="Select a destination..."
                x-on:keyup.escape="closeMenus" x-on:keydown.down="focusNext(destinations.length)"
                x-on:keydown.up="focusPrev(destinations.length)"
                x-on:keyup.enter="focusedIndex !== '' && await set('jumpToDestination',filteredDestinations()[focusedIndex].uuid)" />
            <div class="magic-items">
                <template x-if="destinations.length === 0">
                    <div class="magic-item" x-on:click="set('newDestination')">
                        <span>No destination found. Click here to add a new one!</span>
                    </div>
                </template>
                <template x-for="(destination,index) in filteredDestinations" :key="destination.name ?? destination">
                    <div x-on:click="await set('jumpToDestination',destination.uuid)"
                        :class="focusedIndex === index && 'magic-item-focused'"
                        class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                        <span class="px-2 mr-1 text-xs bg-purple-700 rounded">Jump</span>
                        <span x-text="destination.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
    {{-- Private Keys --}}
    <template x-cloak x-if="privateKeysMenu">
        <div x-on:click.outside="closeMenus">
            <input x-ref="search" x-model="search" class="magic-input" placeholder="Select a private key..."
                x-on:keyup.escape="closeMenus" x-on:keydown.down="focusNext(privateKeys.length)"
                x-on:keydown.up="focusPrev(privateKeys.length)"
                x-on:keyup.enter="focusedIndex !== '' && await set('jumpToPrivateKey',filteredPrivateKeys()[focusedIndex].uuid)" />
            <div class="magic-items">
                <template x-if="privateKeys.length === 0">
                    <div class="magic-item" x-on:click="set('newPrivateKey')">
                        <span>No private key found. Click here to add a new one!</span>
                    </div>
                </template>
                <template x-for="(privateKey,index) in filteredPrivateKeys" :key="privateKey.name ?? privateKey">
                    <div x-on:click="await set('jumpToPrivateKey',privateKey.uuid)"
                        :class="focusedIndex === index && 'magic-item-focused'"
                        class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                        <span class="px-2 mr-1 text-xs bg-purple-700 rounded">Jump</span>
                        <span x-text="privateKey.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
    {{-- Sources --}}
    <template x-cloak x-if="sourcesMenu">
        <div x-on:click.outside="closeMenus">
            <input x-ref="search" x-model="search" class="magic-input" placeholder="Select a source..."
                x-on:keyup.escape="closeMenus" x-on:keydown.down="focusNext(sources.length)"
                x-on:keydown.up="focusPrev(sources.length)"
                x-on:keyup.enter="focusedIndex !== '' && await set('jumpToSource',filteredSources()[focusedIndex])" />
            <div class="magic-items">
                <template x-if="sources.length === 0">
                    <div class="magic-item" x-on:click="set('newSource')">
                        <span>No Source found. Click here to add a new one!</span>
                    </div>
                </template>
                <template x-for="(source,index) in filteredSources" :key="source.name ?? source">
                    <div x-on:click="await set('jumpToSource',source)"
                        :class="focusedIndex === index && 'magic-item-focused'"
                        class="py-2 pl-4 cursor-pointer hover:bg-neutral-700">
                        <span class="px-2 mr-1 text-xs bg-purple-700 rounded">Jump</span>
                        <span x-text="source.name"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('magicsearchbar', () => ({
            isMainMenu() {
                return !this.serverMenu &&
                    !this.destinationMenu &&
                    !this.projectMenu &&
                    !this.environmentMenu &&
                    !this.projectsMenu &&
                    !this.destinationsMenu &&
                    !this.privateKeysMenu &&
                    !this.sourcesMenu
            },
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
                this.$watch('privateKeysMenu', () => {
                    this.focus()
                })
                this.$watch('sourcesMenu', () => {
                    this.focus()
                })
            },
            mainMenu: false,
            serverMenu: false,
            destinationMenu: false,
            destinationsMenu: false,
            projectMenu: false,
            projectsMenu: false,
            environmentMenu: false,
            privateKeysMenu: false,
            sourcesMenu: false,
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
            privateKeys: ['Loading...'],
            sources: ['Loading...'],

            focusedIndex: "",
            items: [{
                    name: 'Public Repository',
                    type: 'App',
                    tags: 'application,public,repository,github,gitlab,bitbucket,git',
                },
                {
                    name: 'Private Repository (with GitHub App)',
                    type: 'App',
                    tags: 'application,private,repository,github,gitlab,bitbucket,git',
                },
                {
                    name: 'Private Repository (with Deploy Key)',
                    type: 'App',
                    tags: 'application,private,repository,github,gitlab,bitbucket,git',
                },
                {
                    name: 'Server',
                    type: 'Add',
                    tags: 'new,server',
                    next: 'newServer'
                },
                {
                    name: 'Destination',
                    type: 'Add',
                    tags: 'new,destination',
                    next: 'newDestination'
                },
                {
                    name: 'Private Key',
                    type: 'Add',
                    tags: 'new,private-key,ssh,key',
                    next: 'newPrivateKey'
                },
                {
                    name: 'Source',
                    type: 'Add',
                    tags: 'new,source,github,gitlab,bitbucket',
                    next: 'newSource'
                },

                {
                    name: 'Database',
                    type: 'Add',
                    tags: 'data,database,mysql,postgres,sql,sqlite,redis,mongodb,maria,percona',
                },

                {
                    name: 'Servers',
                    type: 'Jump',
                    tags: 'servers',
                    next: 'server'
                },
                {
                    name: 'Projects',
                    type: 'Jump',
                    tags: 'projects',
                    next: 'projects'
                },
                {
                    name: 'Destinations',
                    type: 'Jump',
                    tags: 'destinations',
                    next: 'destinations'
                },
                {
                    name: 'Private Keys',
                    type: 'Jump',
                    tags: 'private keys,ssh, keys, key',
                    next: 'privateKeys'
                },
                {
                    name: 'Sources',
                    type: 'Jump',
                    tags: 'github,apps,source',
                    next: 'sources'
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
            clearSearch() {
                if (this.search === '') {
                    this.closeMenus()
                    this.$nextTick(() => {
                        if (this.$refs.search) this.$refs.search.blur();
                    })
                    return
                }
                this.search = ''
                this.focusedIndex = ''
            },
            closeMenus() {
                if (this.$refs.search) this.$refs.search.blur();
                this.search = ''
                this.focusedIndex = ''
                this.mainMenu = false
                this.serverMenu = false
                this.destinationMenu = false
                this.projectMenu = false
                this.environmentMenu = false
                this.projectsMenu = false
                this.destinationsMenu = false
                this.privateKeysMenu = false
                this.sourcesMenu = false
            },
            checkMainMenu() {
                if (this.serverMenu) return
                this.mainMenu = true
            },
            filteredItems() {
                if (this.search === '') return this.items
                return this.items.filter(item => {
                    return item.name.toLowerCase().includes(this.search.toLowerCase()) ||
                        item.tags.toLowerCase().includes(this.search.toLowerCase())
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
                if (this.destinations.length === 0) return []
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
            filteredPrivateKeys() {
                if (this.search === '') return this.privateKeys
                return this.privateKeys.filter(privateKey => {
                    return privateKey.name.toLowerCase().includes(this.search
                        .toLowerCase())
                })
            },
            filteredSources() {
                if (this.search === '') return this.sources
                return this.sources.filter(source => {
                    return source.name.toLowerCase().includes(this.search
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
                    this.set('environment', project_uuid)
                    this.set('jump', 'production')
                }
            },
            async newEnvironment() {
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
                    case 'projects':
                        response = await fetch('/magic?projects=true');
                        if (response.ok) {
                            const {
                                projects
                            } = await response.json();
                            this.projects = projects;
                        }
                        this.closeMenus()
                        this.projectsMenu = true
                        break
                    case 'destinations':
                        response = await fetch('/magic?destinations=true');
                        if (response.ok) {
                            const {
                                destinations
                            } = await response.json();
                            this.destinations = destinations;
                        }
                        this.closeMenus()
                        this.destinationsMenu = true
                        break
                    case 'privateKeys':
                        response = await fetch('/magic?privateKeys=true');
                        if (response.ok) {
                            const {
                                privateKeys
                            } = await response.json();
                            this.privateKeys = privateKeys;
                        }
                        this.closeMenus()
                        this.privateKeysMenu = true
                        break
                    case 'sources':
                        response = await fetch('/magic?sources=true');
                        if (response.ok) {
                            const {
                                sources
                            } = await response.json();
                            this.sources = sources;
                        }
                        this.closeMenus()
                        this.sourcesMenu = true
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
                    case 'jumpToProject':
                        window.location = `/project/${id}`
                        this.closeMenus()
                        break
                    case 'jumpToDestination':
                        window.location = `/destination/${id}`
                        this.closeMenus()
                        break
                    case 'jumpToPrivateKey':
                        window.location = `/private-key/${id}`
                        this.closeMenus()
                        break
                    case 'jumpToSource':
                        window.location = `/source/${id.type}/${id.uuid}`
                        this.closeMenus()
                        break
                    case 'newServer':
                        window.location = `/server/new`
                        this.closeMenus()
                        break
                    case 'newDestination':
                        if (this.selectedServer !== '') {
                            window.location = `/destination/new?server=${this.selectedServer}`
                            return
                        }
                        window.location = `/destination/new`
                        this.closeMenus()
                        break
                    case 'newPrivateKey':
                        window.location = `/private-key/new`
                        this.closeMenus()
                        break
                    case 'newSource':
                        window.location = `/source/new`
                        this.closeMenus()
                        break
                }
            }
        }))
    })
</script>

<script setup lang="ts">
import { ref, inject } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Plus } from 'lucide-vue-next'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import ResourceBox from '@/components/ResourceBox.vue'
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area'
import MainView from '@/components/MainView.vue'
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card'

import type { User } from '@/types/UserType'
import type { Project } from '@/types/ProjectType'
import type { Server } from '@/types/ServerType'
import type { CustomBreadcrumbItem } from '@/types/BreadcrumbsType'

const route = inject('route') as (name: string) => string

const props = defineProps<{
    user: User,
    projects: Project[],
    servers: Server[],
    sources: any[],
    destinations: any[]
}>()

let currentTab = ref(new URL(window.location.href).searchParams.get('tab') || 'projects')
const projects = ref(props.projects)
const servers = ref(props.servers)
const sources = ref(props.sources)
const destinations = ref(props.destinations)
const search = ref('')

function capitalize(word: string) {
    word = word.replace(/-/g, ' ')
    return word.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()).join(' ')
}

function saveCurrentTab(tab: string) {
    currentTab.value = tab
    window.history.pushState({}, '', window.location.pathname + '?tab=' + tab)
    breadcrumb.value = [
        {
            label: 'Dashboard',
            href: route('next_dashboard')
        },
        {
            label: capitalize(tab),
            href: route('next_dashboard')
        }
    ]
}

function searchProjects(value: string) {
    search.value = value
    if (!value) {
        projects.value = props.projects
        servers.value = props.servers
        sources.value = props.sources
        destinations.value = props.destinations
        return
    }
    projects.value = props.projects.filter(project => project.name.toLowerCase().includes(value.toLowerCase()))
    servers.value = props.servers.filter(server => server.name.toLowerCase().includes(value.toLowerCase()))
    sources.value = props.sources.filter(source => source.name.toLowerCase().includes(value.toLowerCase()))
    destinations.value = props.destinations.filter(destination => destination.name.toLowerCase().includes(value.toLowerCase()))

}

const breadcrumb = ref<CustomBreadcrumbItem[]>([
    {
        label: 'Dashboard',
        href: route('next_dashboard')
    },
    {
        label: capitalize(currentTab.value),
        href: route('next_dashboard')
    }
])

</script>

<template>
    <MainView @search="searchProjects" :breadcrumb="breadcrumb">
        <div v-if="search">
            <Tabs :default-value="currentTab" class="py-2 opacity-30">
                <ScrollArea>
                    <TabsList
                        class="dark:bg-transparent text-left md:justify-start md:items-start justify-center items-center border-b border-border pb-2">
                        <TabsTrigger value="projects" disabled>
                            Projects

                        </TabsTrigger>
                        <TabsTrigger value="servers" disabled>
                            Servers
                        </TabsTrigger>
                        <TabsTrigger value="git-sources" disabled>
                            Git Sources
                        </TabsTrigger>
                        <TabsTrigger value="destinations" disabled>
                            Destinations
                        </TabsTrigger>
                        <TabsTrigger value="keys" disabled>
                            Keys & Tokens
                        </TabsTrigger>
                    </TabsList>
                    <ScrollBar orientation="horizontal" />
                </ScrollArea>
            </Tabs>

            <div v-if="projects.length > 0 || servers.length > 0 || sources.length > 0 || destinations.length > 0">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 gap-2">
                    <div v-for="project in projects" :key="project.uuid">
                        <ResourceBox type="project" :href="route('next_project', project.uuid)" :name="project.name"
                            :description="project.description" :environments="project.environments" />
                    </div>
                    <div v-for="server in servers" :key="server.uuid">
                        <ResourceBox type="server" :href="route('next_project', server.uuid)" :name="server.name"
                            :description="server.description" />
                    </div>
                    <div v-for="source in sources" :key="source.uuid">
                        <ResourceBox type="source" :href="route('next_project', source.uuid)" :name="source.name"
                            :description="source.description" />
                    </div>
                    <div v-for="destination in destinations" :key="destination.uuid">
                        <ResourceBox type="destination" :href="route('next_project', destination.uuid)"
                            :name="destination.name" :description="destination.description" />
                    </div>
                </div>
            </div>
            <div v-else>
                <p class="text-sm text-muted-foreground">Nothing found.</p>
            </div>
        </div>
        <div v-else>
            <Tabs :default-value="currentTab" orientation="vertical" class="w-full pt-2">
                <TabsList
                    class="dark:bg-transparent text-left md:justify-start md:items-start justify-center items-center border-b border-border pb-2">
                    <TabsTrigger value="projects" @click="saveCurrentTab('projects')">
                        Projects
                    </TabsTrigger>
                    <TabsTrigger value="servers" @click="saveCurrentTab('servers')">
                        Servers
                    </TabsTrigger>
                    <TabsTrigger value="git-sources" @click="saveCurrentTab('git-sources')">
                        Git Sources
                    </TabsTrigger>
                    <TabsTrigger value="destinations" @click="saveCurrentTab('destinations')">
                        Destinations
                    </TabsTrigger>
                    <TabsTrigger value="keys" @click="saveCurrentTab('keys')">
                        Keys & Tokens
                    </TabsTrigger>
                </TabsList>
                <TabsContent value="projects">
                    <div class="resource-box-container">
                        <div v-for="project in projects" :key="project.uuid">
                            <ResourceBox type="project" :href="route('next_project', project.uuid)" :name="project.name"
                                :description="project.description" :environments="project.environments" />
                        </div>
                        <ResourceBox :new="true" type="project" :href="route('next_projects')" name="New Project" />
                    </div>
                </TabsContent>
                <TabsContent value="servers">
                    <div class="resource-box-container">
                        <div v-for="server in servers" :key="server.uuid">
                            <ResourceBox type="server" :href="route('next_project', server.uuid)" :name="server.name"
                                :description="server.description" />
                        </div>
                        <ResourceBox :new="true" type="server" :href="route('next_projects')" name="New Server" />
                    </div>
                </TabsContent>
                <TabsContent value="git-sources">
                    <div class="resource-box-container">
                        <div v-for="source in sources" :key="source.uuid">
                            <ResourceBox type="source" :href="route('next_project', source.uuid)" :name="source.name"
                                :description="source.description" />
                        </div>
                        <ResourceBox :new="true" type="source" :href="route('next_projects')" name="New Source" />
                    </div>
                </TabsContent>
                <TabsContent value="destinations">
                    <div class="resource-box-container">
                        <div v-for="destination in destinations" :key="destination.uuid">
                            <ResourceBox type="destination" :href="route('next_project', destination.uuid)"
                                :name="destination.name" :description="destination.description" />
                        </div>
                        <ResourceBox :new="true" type="destination" :href="route('next_projects')"
                            name="New Destination" />
                    </div>
                </TabsContent>
                <!-- <TabsContent value="keys">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 text-left">
                        <div v-for="server in servers" :key="server.uuid">
                            <ResourceBox :href="route('next_projects')" :name="server.name" :description="server.description" />
                        </div>
                    </div>
                </TabsContent> -->
            </Tabs>
        </div>
    </MainView>
</template>

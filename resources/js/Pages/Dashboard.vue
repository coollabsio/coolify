<script setup lang="ts">
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area'
import { Link, WhenVisible } from '@inertiajs/vue3'
import MainView from '@/components/MainView.vue'
import { ref, inject } from 'vue'

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
            href: route('dashboard')
        },
        {
            label: capitalize(tab),
            href: route('dashboard')
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
    projects.value = props.projects.filter(project => project.name.toLowerCase().includes(value.toLowerCase()) || project.description.toLowerCase().includes(value.toLowerCase()))
    servers.value = props.servers.filter(server => server.name.toLowerCase().includes(value.toLowerCase()) || server.description.toLowerCase().includes(value.toLowerCase()))
    sources.value = props.sources.filter(source => source.name.toLowerCase().includes(value.toLowerCase()) || source.description.toLowerCase().includes(value.toLowerCase()))
    destinations.value = props.destinations.filter(destination => destination.name.toLowerCase().includes(value.toLowerCase() || destination.description.toLowerCase().includes(value.toLowerCase())))

}

const breadcrumb = ref<CustomBreadcrumbItem[]>([
    {
        label: 'Dashboard',
        href: route('dashboard')
    },
    {
        label: capitalize(currentTab.value),
        href: route('dashboard')
    }
])

</script>

<template>
    <MainView @search="searchProjects" :breadcrumb="breadcrumb">
        <template #title>
            Dashboard
        </template>
        <template #subtitle>Your self-hosted infrastructure.</template>
        <div v-if="search">
            <Tabs :default-value="currentTab" class="pb-2 opacity-50">
                <ScrollArea>
                    <TabsList>
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

            <div v-if="projects.length > 0 || servers.length > 0 || sources.length > 0">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                    <div v-for="project in projects" :key="project.uuid">
                        <Link :href="route('projects')"
                            class="flex flex-col bg-coolgray-100 rounded-lg p-4 border dark:border-black hover:bg-coollabs transition-all cursor-pointer h-24 group">
                        <div class="text-sm font-bold text-foreground">{{ project.name }}</div>
                        <p class="text-xs text-muted-foreground group-hover:dark:text-white font-bold">{{ project.description
                            }}</p>
                        </Link>
                    </div>
                    <div v-for="server in servers" :key="server.uuid">
                        <Link :href="route('projects')"
                            class="flex flex-col bg-coolgray-100 rounded-lg p-4 border dark:border-black hover:bg-coollabs transition-all cursor-pointer h-24 group">
                        <div class="text-sm font-bold text-foreground">{{ server.name }}</div>
                        <p class="text-xs text-muted-foreground group-hover:dark:text-white font-bold">{{ server.description
                            }}</p>
                        </Link>
                    </div>
                    <div v-for="source in sources" :key="source.uuid">
                        <Link :href="route('projects')"
                            class="flex flex-col bg-coolgray-100 rounded-lg p-4 border dark:border-black hover:bg-coollabs transition-all cursor-pointer h-24 group">
                        <div class="text-sm font-bold text-foreground">{{ source.name }}</div>
                        <p class="text-xs text-muted-foreground group-hover:dark:text-white font-bold">{{ source.description
                            }}</p>
                        </Link>
                    </div>
                    <div v-for="destination in destinations" :key="destination.uuid">
                        <Link :href="route('projects')"
                            class="flex flex-col bg-coolgray-100 rounded-lg p-4 border dark:border-black hover:bg-coollabs transition-all cursor-pointer h-24 group">
                        <div class="text-sm font-bold text-foreground">{{ destination.name }}</div>
                        <p class="text-xs text-muted-foreground group-hover:dark:text-white font-bold">{{ destination.description
                            }}</p>
                        </Link>
                    </div>
                </div>
            </div>
            <div v-else>
                <p class="text-sm text-muted-foreground">Nothing found.</p>
            </div>
        </div>
        <div v-else>
            <Tabs :default-value="currentTab" orientation="vertical">
                <TabsList class="bg-card text-left">
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
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 text-left">
                        <div v-for="project in projects" :key="project.uuid">
                            <Link :href="route('projects')"
                                class="flex flex-col bg-coolgray-100 rounded-lg p-4 border dark:border-black hover:bg-coollabs transition-all cursor-pointer h-24 group">
                            <div class="text-sm font-bold text-foreground">{{ project.name }}</div>
                            <p class="text-xs text-muted-foreground group-hover:dark:text-white font-bold">{{ project.description
                                }}</p>
                            </Link>
                        </div>
                    </div>
                </TabsContent>
                <TabsContent value="servers">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 text-left">
                        <div v-for="server in servers" :key="server.uuid">
                            <Link :href="route('projects')"
                                class="flex flex-col bg-coolgray-100 rounded-lg p-4 border dark:border-black hover:bg-coollabs transition-all cursor-pointer h-24 group">
                            <div class="text-sm font-bold text-foreground">{{ server.name }}</div>
                            <p class="text-xs text-muted-foreground group-hover:dark:text-white font-bold">{{ server.description
                                }}</p>
                            </Link>
                        </div>
                    </div>
                </TabsContent>
                <TabsContent value="git-sources">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 text-left">
                        <div v-for="source in sources" :key="source.uuid">
                            <Link :href="route('projects')"
                                class="flex flex-col bg-coolgray-100 rounded-lg p-4 border dark:border-black hover:bg-coollabs transition-all cursor-pointer h-24 group">
                            <div class="text-sm font-bold text-foreground">{{ source.name }}</div>
                            <p class="text-xs text-muted-foreground group-hover:dark:text-white font-bold">{{ source.description
                                }}</p>
                            </Link>
                        </div>
                    </div>
                </TabsContent>
                <TabsContent value="destinations">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 text-left">
                        <div v-for="destination in destinations" :key="destination.uuid">
                            <Link :href="route('projects')"
                                class="flex flex-col bg-coolgray-100 rounded-lg p-4 border dark:border-black hover:bg-coollabs transition-all cursor-pointer h-24 group">
                            <div class="text-sm font-bold text-foreground">{{ destination.name }}</div>
                            <p class="text-xs text-muted-foreground group-hover:dark:text-white font-bold">{{
                                destination.description
                                }}</p>
                            </Link>
                        </div>
                    </div>
                </TabsContent>
                <!-- <TabsContent value="keys">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 text-left">
                        <div v-for="server in servers" :key="server.uuid">
                            <Link :href="route('projects')"
                                class="flex flex-col bg-coolgray-100 rounded-lg p-4 border dark:border-black hover:bg-coollabs transition-all cursor-pointer h-24 group">
                            <div class="text-sm font-bold text-foreground">{{ server.name }}</div>
                            <p class="text-xs text-muted-foreground group-hover:dark:text-white font-bold">{{ server.description
                                }}</p>
                            </Link>
                        </div>
                    </div>
                </TabsContent> -->
            </Tabs>
        </div>
    </MainView>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import ResourceBox from '@/components/ResourceBox.vue'
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area'
import MainView from '@/components/MainView.vue'

import type { User } from '@/types/UserType'
import type { Project } from '@/types/ProjectType'
import type { Server } from '@/types/ServerType'
import type { CustomBreadcrumbItem } from '@/types/BreadcrumbsType'
import { Application } from '@/types/ApplicationType'

import { route } from '@/route'

const props = defineProps<{
    user: User,
    projects: Project[],
    servers: Server[],
    applications: Application[],
    databases: any[],
    services: any[],
    // sources: any[],
    // destinations: any[]
}>()

let currentTab = ref(new URL(window.location.href).searchParams.get('tab') || 'projects')
const projects = ref(props.projects)
const servers = ref(props.servers)
const applications = ref(props.applications)
const databases = ref(props.databases)
const services = ref(props.services)
// const sources = ref(props.sources)
// const destinations = ref(props.destinations)
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
        applications.value = props.applications
        databases.value = props.databases
        services.value = props.services
        // sources.value = props.sources
        // destinations.value = props.destinations
        return
    }
    projects.value = props.projects.filter(project => project.name.toLowerCase().includes(value.toLowerCase()))
    servers.value = props.servers.filter(server => server.name.toLowerCase().includes(value.toLowerCase()))
    applications.value = props.applications.filter(application => application.name.toLowerCase().includes(value.toLowerCase()))
    databases.value = props.databases.filter(database => database.name.toLowerCase().includes(value.toLowerCase()))
    services.value = props.services.filter(service => service.name.toLowerCase().includes(value.toLowerCase()))
    // sources.value = props.sources.filter(source => source.name.toLowerCase().includes(value.toLowerCase()))
    // destinations.value = props.destinations.filter(destination => destination.name.toLowerCase().includes(value.toLowerCase()))

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
            <Tabs :default-value="currentTab" orientation="vertical"
                class="w-full pt-2 max-w-[calc(100vw-30px)] md:max-w-full">
                <ScrollArea>
                    <TabsList
                        class="dark:bg-transparent w-full flex h-10 items-center justify-start space-x-1 rounded-lg bg-muted p-1 md:border-b border-border mb-2">
                        <TabsTrigger value="projects" disabled>
                            Projects
                        </TabsTrigger>
                        <TabsTrigger value="servers" disabled>
                            Servers
                        </TabsTrigger>
                        <TabsTrigger value="applications" disabled>
                            Applications
                        </TabsTrigger>
                        <TabsTrigger value="databases" disabled>
                            Databases
                        </TabsTrigger>
                        <TabsTrigger value="services" disabled>
                            Services
                        </TabsTrigger>
                        <!-- <TabsTrigger value="git-sources" disabled>
                            Git Sources
                        </TabsTrigger>
                        <TabsTrigger value="destinations" disabled>
                            Destinations
                        </TabsTrigger>
                        <TabsTrigger value="keys" disabled>
                            Keys & Tokens
                        </TabsTrigger> -->
                    </TabsList>
                    <ScrollBar orientation="horizontal" class="h-1.5" />
                </ScrollArea>
            </Tabs>

            <div v-if="projects.length > 0 || servers.length > 0 || applications.length > 0 || databases.length > 0 || services.length > 0"
                class="bg-coolgray-100 p-2 rounded-xl mt-2">
                <div class="resource-box-container">
                    <div v-for="project in projects" :key="project.uuid">
                        <ResourceBox type="project" :href="route('next_project', project.uuid)" :name="project.name"
                            :description="project.description" :environments="project.environments" />
                    </div>
                    <div v-for="server in servers" :key="server.uuid">
                        <ResourceBox type="server" :href="route('next_project', server.uuid)" :name="server.name"
                            :description="server.description" />
                    </div>
                    <div v-for="application in applications" :key="application.uuid">
                        <ResourceBox type="application" :href="route('next_project', application.uuid)"
                            :name="application.name" :description="application.description" />
                    </div>
                    <div v-for="database in databases" :key="database.uuid">
                        <ResourceBox :type="database.type" :href="route('next_project', database.uuid)"
                            :name="database.name" :description="database.description" />
                    </div>
                    <div v-for="service in services" :key="service.uuid">
                        <ResourceBox type="service" :href="route('next_project', service.uuid)" :name="service.name"
                            :description="service.description" />
                    </div>
                </div>
            </div>
            <div v-else>
                <p class="text-sm text-muted-foreground">Nothing found.</p>
            </div>
        </div>
        <div v-else>
            <Tabs :default-value="currentTab" orientation="vertical"
                class="w-full pt-2 max-w-[calc(100vw-30px)] md:max-w-full">
                <ScrollArea>
                    <TabsList
                        class="dark:bg-transparent w-full flex h-10 items-center justify-start space-x-1 rounded-lg bg-muted p-1 md:border-b border-border mb-2">
                        <TabsTrigger value="projects" @click="saveCurrentTab('projects')"
                            class="rounded-xl dark:data-[state=active]:bg-coollabs">
                            Projects
                        </TabsTrigger>
                        <TabsTrigger value="servers" @click="saveCurrentTab('servers')"
                            class="rounded-xl dark:data-[state=active]:bg-coollabs">
                            Servers
                        </TabsTrigger>
                        <TabsTrigger value="applications" @click="saveCurrentTab('applications')"
                            class="rounded-xl dark:data-[state=active]:bg-coollabs">
                            Applications
                        </TabsTrigger>
                        <TabsTrigger value="databases" @click="saveCurrentTab('databases')"
                            class="rounded-xl dark:data-[state=active]:bg-coollabs">
                            Databases
                        </TabsTrigger>
                        <TabsTrigger value="services" @click="saveCurrentTab('services')"
                            class="rounded-xl dark:data-[state=active]:bg-coollabs">
                            Services
                        </TabsTrigger>
                    </TabsList>
                    <ScrollBar orientation="horizontal" class="h-1.5" />
                </ScrollArea>
                <TabsContent value="projects" class="bg-coolgray-100 p-2 rounded-xl">
                    <div class="resource-box-container">
                        <div v-for="project in projects" :key="project.uuid">
                            <ResourceBox type="project" :href="route('next_project', project.uuid)" :name="project.name"
                                :description="project.description" :environments="project.environments" />
                        </div>
                        <ResourceBox :new="true" type="project" :href="route('next_projects')" name="New Project" />
                    </div>
                </TabsContent>
                <TabsContent value="servers" class="bg-coolgray-100 p-2 rounded-xl">
                    <div class="resource-box-container">
                        <div v-for="server in servers" :key="server.uuid">
                            <ResourceBox type="server" :href="route('next_server', server.uuid)" :name="server.name"
                                :description="server.description" />
                        </div>
                        <ResourceBox :new="true" type="server" :href="route('next_projects')" name="New Server" />
                    </div>
                </TabsContent>
                <TabsContent value="applications" class="bg-coolgray-100 p-2 rounded-xl">
                    <div class="resource-box-container">
                        <div v-for="application in applications" :key="application.uuid">
                            <ResourceBox type="application" :href="route('next_project', application.uuid)"
                                :name="application.name" :description="application.description" />
                        </div>
                        <ResourceBox :new="true" type="application" :href="route('next_projects')"
                            name="New Application" />
                    </div>
                </TabsContent>
                <TabsContent value="databases" class="bg-coolgray-100 p-2 rounded-xl">
                    <div class="resource-box-container">
                        <div v-for="database in databases" :key="database.uuid">
                            <ResourceBox :type="database.type" :href="route('next_project', database.uuid)"
                                :name="database.name" :description="database.description" />
                        </div>
                        <!-- <ResourceBox :new="true" type="postgresql" :href="route('next_projects')" name="New Database" /> -->
                    </div>
                </TabsContent>
                <TabsContent value="services" class="bg-coolgray-100 p-2 rounded-xl">
                    <div class="resource-box-container">
                        <div v-for="service in services" :key="service.uuid">
                            <ResourceBox type="service" :href="route('next_project', service.uuid)" :name="service.name"
                                :description="service.description" />

                        </div>
                        <!-- <ResourceBox :new="true" type="postgresql" :href="route('next_projects')" name="New Database" /> -->
                    </div>
                </TabsContent>
                <!-- <TabsContent value="git-sources" class="bg-coolgray-100 p-2 rounded-xl">
                    <div class="resource-box-container">
                        <div v-for="source in sources" :key="source.uuid">
                            <ResourceBox type="source" :href="route('next_project', source.uuid)" :name="source.name"
                                :description="source.description" />
                        </div>
                        <ResourceBox :new="true" type="source" :href="route('next_projects')" name="New Source" />
                    </div>
                </TabsContent>
                <TabsContent value="destinations" class="bg-coolgray-100 p-2 rounded-xl">
                    <div class="resource-box-container">
                        <div v-for="destination in destinations" :key="destination.uuid">
                            <ResourceBox type="destination" :href="route('next_project', destination.uuid)"
                                :name="destination.name" :description="destination.description" />
                        </div>
                        <ResourceBox :new="true" type="destination" :href="route('next_projects')"
                            name="New Destination" />
                    </div>
                </TabsContent> -->
                <!-- <TabsContent value="keys" class="bg-coolgray-100 p-2 rounded-xl">
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

<script setup lang="ts">
import MainView from '@/components/MainView.vue'
import { ref } from 'vue'

import type { CustomBreadcrumbItem } from '@/types/BreadcrumbsType'
import type { Project } from '@/types/ProjectType'
import { route } from '@/route'

const props = defineProps<{
    projects: Project[]
}>()

const projects = ref(props.projects)

function searchProjects(value: string) {
    projects.value = props.projects.filter(project => project.name.toLowerCase().includes(value.toLowerCase()))
}

const breadcrumb = ref<CustomBreadcrumbItem[]>([
    {
        label: 'Projects',
        href: route('next_projects')
    }
])
</script>

<template>
    <MainView @search="searchProjects" :breadcrumb="breadcrumb">
        <template #title>
            Projects
        </template>
        <template #subtitle>Manage your projects.</template>

        <div v-if="projects.length > 0">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                <div v-for="project in projects" :key="project.uuid">
                    <Link :href="route('projects')"
                        class="flex flex-col bg-coolgray-100 rounded-lg p-4 border dark:border-black hover:bg-coollabs transition-all cursor-pointer h-24 group">
                    <h3 class="text-lg font-bold">{{ project.name }}</h3>
                    <p class="text-sm text-muted-foreground group-hover:dark:text-white">{{ project.description
                        }}</p>
                    </Link>
                </div>
            </div>
        </div>
        <div v-else>
            <p class="text-sm text-muted-foreground">No projects found.</p>
        </div>
    </MainView>
</template>

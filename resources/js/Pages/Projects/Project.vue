<script setup lang="ts">
import MainView from '@/components/MainView.vue'
import ResourceBox from '@/components/ResourceBox.vue'
import { ref } from 'vue'
import { route } from '@/route'

import type { CustomBreadcrumbItem } from '@/types/BreadcrumbsType'
import type { Project } from '@/types/ProjectType'
import type { Environment } from '@/types/EnvironmentType'

const props = defineProps<{
    project: Project
    environments: Environment[]
}>()

const breadcrumb = ref<CustomBreadcrumbItem[]>([
    {
        label: 'Projects',
        href: route('next_dashboard', { tab: 'projects' }, false)
    },
    {
        label: props.project.name,
        href: route('next_project', props.project.uuid, false)
    }
])
</script>

<template>
    <MainView :breadcrumb="breadcrumb">
        <template #title>
            {{ props.project.name }}
        </template>
        <div class="resource-box-container md:grid-cols-2 lg:grid-cols-2 xl:grid-cols-2">
            <div v-for="environment in environments" :key="environment.uuid">
                <ResourceBox type="environment"
                    :href="route('next_environment', { project_uuid: props.project.uuid, environment_uuid: environment.uuid }, false)"
                    :name="environment.name" :description="environment.description" />
            </div>
        </div>
    </MainView>
</template>

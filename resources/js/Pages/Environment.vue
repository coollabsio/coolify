<script setup lang="ts">
import MainView from '@/components/MainView.vue'
import ResourceBox from '@/components/ResourceBox.vue'
import { ref } from 'vue'

import type { CustomBreadcrumbItem } from '@/types/BreadcrumbsType'
import type { Project } from '@/types/ProjectType'
import type { Environment } from '@/types/EnvironmentType'

const props = defineProps<{
    project: Project
    environment: Environment
}>()

const breadcrumb = ref<CustomBreadcrumbItem[]>([
    {
        label: 'Projects',
        href: route('next_dashboard', { tab: 'projects' })
    },
    {
        label: props.project.name,
        href: route('next_project', props.project.uuid)
    },
    {
        label: props.environment.name,
        href: route('next_environment', { project_uuid: props.project.uuid, environment_uuid: props.environment.uuid })
    }
])
</script>

<template>
    <MainView :breadcrumb="breadcrumb">
        <template #title>
            {{ props.environment.name }}
        </template>
    </MainView>
</template>

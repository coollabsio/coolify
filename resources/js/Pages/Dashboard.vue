<script setup lang="ts">
import MainView from '@/components/MainView.vue'
import { Link } from '@inertiajs/vue3'
import type { User } from '@/types/UserType'
import type { Project } from '@/types/ProjectType'
import { ref, inject } from 'vue'
import { Input } from '@/components/ui/input'
import { useDebounceFn } from '@vueuse/core'
const props = defineProps<{ user: User, projects: Project[] }>()

const projects = ref(props.projects)
const search = ref('')
const debouncedSearch = useDebounceFn(searchProjects, 100)

const route = inject('route')
console.log(route('projects'))
function searchProjects() {
    projects.value = props.projects.filter(project => project.name.toLowerCase().includes(search.value.toLowerCase()))
}

</script>

<template>
    <MainView>
        <template #title>
            Dashboard
        </template>
        <template #subtitle>Your self-hosted infrastructure.</template>
        <Input size="xs" v-model="search" placeholder="Search" @update:model-value="debouncedSearch" />
        <div v-for="project in projects" :key="project.uuid">
            <Link :href="route('projects')" class="flex flex-col bg-coolgray-100 rounded-lg p-4 border dark:border-black hover:bg-coolgray-200 transition-all duration-300 cursor-pointer">
                <h3 class="text-lg font-bold">{{ project.name }}</h3>
                <p class="text-sm text-muted-foreground">{{ project.description }}</p>
            </Link>
        </div>
    </MainView>
</template>

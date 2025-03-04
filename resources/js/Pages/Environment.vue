<script setup lang="ts">
import MainView from '@/components/MainView.vue'
import ResourceBox from '@/components/ResourceBox.vue'
import { ref } from 'vue'

import type { CustomBreadcrumbItem } from '@/types/BreadcrumbsType'
import type { Project } from '@/types/ProjectType'
import type { Environment } from '@/types/EnvironmentType'
import { Application } from '@/types/ApplicationType'
import { Postgresql } from '@/types/PostgresqlType'
import { Redis } from '@/types/RedisType'
import { Mongodb } from '@/types/MongodbType'
import { Mysql } from '@/types/MysqlType'
import { Mariadb } from '@/types/MariadbType'
import { route } from '@/route'

const props = defineProps<{
    project: Project
    environment: Environment
    applications: Application[]
    services: any[]
    postgresqls: Postgresql[]
    redis: Redis[]
    mongodbs: Mongodb[]
    mysqls: Mysql[]
    mariadbs: Mariadb[]
}>()

const applications = ref(props.applications)
const services = ref(props.services)
const postgresqls = ref(props.postgresqls)
const search = ref('')

const breadcrumb = ref<CustomBreadcrumbItem[]>([
    {
        label: 'Projects',
        href: route('next_dashboard', { tab: 'projects' }, false)
    },
    {
        label: props.project.name,
        href: route('next_project', props.project.uuid, false)
    },
    {
        label: props.environment.name,
        href: route('next_environment', { project_uuid: props.project.uuid, environment_uuid: props.environment.uuid }, false)
    }
])

const searchResources = (value: string) => {
    search.value = value
    if (!value) {
        applications.value = props.applications
        services.value = props.services
        postgresqls.value = props.postgresqls
        return
    }
    applications.value = props.applications.filter(application => application.name.toLowerCase().includes(value.toLowerCase()))
    services.value = props.services.filter(service => service.name.toLowerCase().includes(value.toLowerCase()))
    postgresqls.value = props.postgresqls.filter(postgresql => postgresql.name.toLowerCase().includes(value.toLowerCase()))

}
</script>

<template>
    <MainView :breadcrumb="breadcrumb" @search="searchResources">
        <template #title>
            {{ props.environment.name }}
        </template>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="col-span-full" v-if="applications.length > 0">
                <h3 class="text-sm font-bold text-foreground">Applications</h3>
            </div>
            <div v-for="application in applications" :key="application.uuid">
                <ResourceBox type="application"
                    :href="route('next_environment', { project_uuid: props.project.uuid, environment_uuid: props.environment.uuid, application_uuid: application.uuid }, false)"
                    :name="application.name" :description="application.description" />
            </div>
            <div class="col-span-full" v-if="postgresqls.length > 0">
                <h3 class="text-sm font-bold text-foreground">Databases</h3>
            </div>
            <div v-for="postgresql in postgresqls" :key="postgresql.uuid">
                <ResourceBox type="standalone-postgresql"
                    :href="route('next_environment', { project_uuid: props.project.uuid, environment_uuid: props.environment.uuid, standalone_postgresql_uuid: postgresql.uuid }, false)"
                    :name="postgresql.name" :description="postgresql.description" />
            </div>

            <div class="col-span-full" v-if="services.length > 0">
                <h3 class="text-sm font-bold text-foreground">Services</h3>
            </div>
            <div v-for="service in services" :key="service.uuid">
                <ResourceBox type="service"
                    :href="route('next_environment', { project_uuid: props.project.uuid, environment_uuid: props.environment.uuid, service_uuid: service.uuid }, false)"
                    :name="service.name" :description="service.description" />
            </div>
        </div>
    </MainView>
</template>

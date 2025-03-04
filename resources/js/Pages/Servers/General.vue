<script setup lang="ts">
import { ref } from 'vue';
import MainView from '@/components/MainView.vue';
import General from '@/components/Forms/Server/General.vue';

import { getServerBreadcrumbs, getServerSidebarNavItems } from '@/config/server/shared';
import { Server } from '@/types/ServerType';

const props = defineProps<{
    server: Server,
}>()

const breadcrumb = ref(getServerBreadcrumbs(props.server.name, props.server.uuid))
const sidebarNavItems = getServerSidebarNavItems(props.server.uuid)

</script>

<template>
    <MainView hideSearch :breadcrumb="breadcrumb" :sidebarNavItems="sidebarNavItems">
        <template #title>
            {{ server.name }}
        </template>
        <template #subtitle>
            {{ server.description }}
        </template>
        <template #main>
            <General :uuid="server.uuid" :name="server.name" :description="server.description"
                :wildcard_domain="server.settings.wildcard_domain" :server_timezone="server.settings.server_timezone" />
        </template>
    </MainView>
</template>
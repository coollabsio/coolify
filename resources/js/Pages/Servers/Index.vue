<script setup lang="ts">
import MainView from '@/components/MainView.vue';
import ResourceBox from '@/components/ResourceBox.vue';
import { route } from '@/route';
import { CustomBreadcrumbItem } from '@/types/BreadcrumbsType';
import { Server } from '@/types/ServerType';
import { ref } from 'vue';

const props = defineProps<{
    servers: Server[];
}>();

const servers = ref(props.servers);
const search = ref('');

const breadcrumb = ref<CustomBreadcrumbItem[]>([
    {
        label: 'Dashboard',
        href: route('next_dashboard'),
    },
    {
        label: 'Servers',
        href: route('next_servers'),
    },
]);

const searchServers = (value: string) => {
    search.value = value;
    servers.value = props.servers.filter(server => server.name.toLowerCase().includes(value.toLowerCase()));
};

</script>

<template>
    <MainView @search="searchServers" :breadcrumb="breadcrumb">
        <div v-if="servers.length > 0" class="resource-box-container">
            <div v-for="server in servers" :key="server.uuid">
                <ResourceBox type="server" :href="route('next_server', server.uuid)" :name="server.name"
                    :description="server.description" />
            </div>
        </div>
        <div v-else class=" text-muted-foreground">
            <p>No servers found</p>
        </div>
    </MainView>
</template>

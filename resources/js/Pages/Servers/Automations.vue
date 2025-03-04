<script setup lang="ts">
import { ref } from 'vue';
import MainView from '@/components/MainView.vue';
import Automations from '@/components/Forms/Server/Automations.vue';
import { getServerBreadcrumbs, getServerSidebarNavItems } from '@/config/server/shared';
import { Server } from '@/types/ServerType';

const props = defineProps<{
  server: Server;
}>();

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
      <Automations :uuid="server.uuid" :docker_cleanup_frequency="server.settings.docker_cleanup_frequency"
        :docker_cleanup_threshold="server.settings.docker_cleanup_threshold"
        :force_docker_cleanup="server.settings.force_docker_cleanup" />
    </template>
  </MainView>
</template>
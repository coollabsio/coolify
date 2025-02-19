<script setup lang="ts">
import MainView from '@/components/MainView.vue';
import { route } from '@/route';
import { CustomBreadcrumbItem } from '@/types/BreadcrumbsType';
import { Server } from '@/types/ServerType';
import { ref } from 'vue';

defineProps<{
  servers: Server[];
}>();

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

</script>

<template>
  <MainView :breadcrumb="breadcrumb">
    <div>
      <h1>Servers</h1>
    </div>
    <div v-for="server in servers" :key="server.uuid">
      <h2>{{ server.name }}</h2>
      <p>{{ server.description }}</p>
    </div>
  </MainView>
</template>
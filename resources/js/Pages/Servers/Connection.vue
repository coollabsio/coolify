<script setup lang="ts">

import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { route } from '@/route'
import { Bot, ChartSpline, SettingsIcon, Unplug } from 'lucide-vue-next';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area';
import MainView from '@/components/MainView.vue';
import Aside from '@/components/Aside.vue';
import { CustomBreadcrumbItem } from '@/types/BreadcrumbsType';
import { Server } from '@/types/ServerType';
import { SidebarNavItem } from '@/types/SidebarNavItemType';
import Connection from '@/components/Forms/Server/Connection.vue';

const props = defineProps<{
  server: Server,
  private_keys: {
    id: number
    uuid: string
    name: string
  }[]
}>()


const breadcrumb = ref<CustomBreadcrumbItem[]>([
  {
    label: 'Dashboard',
    href: route('next_dashboard')
  },
  {
    label: 'Servers',
    href: route('next_servers')
  },
  {
    label: props.server.name,
    href: route('next_server', props.server.uuid)
  }
])


const sidebarNavItems: SidebarNavItem[] = [
  {
    title: 'General',
    icon: SettingsIcon,
    href: route('next_server', props.server.uuid),
  },
  {
    title: 'Connection',
    icon: Unplug,
    href: route('next_server_connection', props.server.uuid),
  },
  {
    title: 'Automations',
    icon: Bot,
    href: route('next_server', props.server.uuid),
  },
  {
    title: 'Metrics',
    icon: ChartSpline,
    href: route('next_server', props.server.uuid),
  }
]

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
      <Connection :uuid="server.uuid" :name="server.name" :description="server.description" :ip="server.ip"
        :port="server.port" :user="server.user" :private_key="server.privateKey" :private_keys="props.private_keys" />
    </template>
  </MainView>
</template>
<script setup lang="ts">

import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { route } from '@/route'
import { Bot, ChartSpline, SettingsIcon, Unplug } from 'lucide-vue-next';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area';
import MainView from '@/components/MainView.vue';

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
  <MainView :breadcrumb="breadcrumb" hideSearch>
    <template #title>
      {{ server.name }}
    </template>
    <template #subtitle>
      {{ server.description }}
    </template>
    <div>
      <div class="flex flex-col space-y-8 lg:flex-row lg:space-x-12 lg:space-y-0">
        <aside class="-mx-2 lg:w-1/5 w-full max-w-[calc(100vw-30px)] md:max-w-full">
          <ScrollArea>
            <nav class="flex space-x-2 lg:flex-col lg:space-x-0 lg:space-y-1">
              <Link v-for="item in sidebarNavItems" :key="item.title" :href="item.href">
              <Button variant="ghost" :class="cn(
                'w-fit xl:w-full text-left justify-start text-muted-foreground hover:bg-primary'
              )">
                <component v-if="item.icon" :is="item.icon" class="w-4 h-4 mr-2" />
                {{ item.title }}
              </Button>
              </Link>
            </nav>
            <ScrollBar orientation="horizontal" class="h-1.5" />
          </ScrollArea>
        </aside>
        <div class="flex-1">
          <Connection :uuid="server.uuid" :name="server.name" :description="server.description" :ip="server.ip"
            :port="server.port" :user="server.user" :private_key="server.privateKey"
            :private_keys="props.private_keys" />
        </div>
      </div>
    </div>
  </MainView>
</template>
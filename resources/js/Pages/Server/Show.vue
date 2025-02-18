<script setup lang="ts">

import MainView from '@/components/MainView.vue';
import { Button } from '@/components/ui/button';
import { Bot, ChartSpline, SettingsIcon, Unplug } from 'lucide-vue-next';

import { cn } from '@/lib/utils';
import { route } from '@/route'
import { CustomBreadcrumbItem } from '@/types/BreadcrumbsType';
import { Server } from '@/types/ServerType';
import { ref } from 'vue';
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area';
import General from '@/components/Server/General.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    server: Server,
}>()


const breadcrumb = ref<CustomBreadcrumbItem[]>([
    {
        label: 'Dashboard',
        href: route('next_dashboard')
    },
    {
        label: props.server.name,
        href: route('next_server', props.server.uuid)
    }
])



function onSubmit(values: Record<string, any>) {
    console.log(values)
}

const sidebarNavItems = [
    {
        title: 'General',
        icon: SettingsIcon,
        href: route('next_server', props.server.uuid),
    },
    {
        title: 'Connections',
        icon: Unplug,
        href: route('next_server', props.server.uuid),
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
    <MainView :breadcrumb="breadcrumb">
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
                    <General />
                </div>
            </div>
        </div>
    </MainView>
</template>
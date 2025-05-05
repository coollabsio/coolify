<script setup lang="ts">
import { Home, Book } from "lucide-vue-next"
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarGroupContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from "@/components/ui/sidebar"
import { Link } from "@inertiajs/vue3";

const { state } = useSidebar();
const items = [
    {
        title: "Dashboard",
        url: route('dashboard'),
        path: '/',
        icon: Home,
    },
    {
        title: "About",
        url: route('about'),
        path: '/about',
        icon: Book,
    }
];
</script>

<template>
    <Sidebar side="left" collapsible="icon" :variant="state == 'expanded' ? 'sidebar' : 'floating'"
        :class="{ 'bg-sidebar': state == 'expanded', 'h-fit': state == 'collapsed' }">
        <SidebarHeader>
            <TeamSwitcher />
        </SidebarHeader>
        <SidebarContent>
            <SidebarGroup>
                <SidebarGroupContent>
                    <SidebarMenu>
                        <SidebarMenuItem v-for="item in items" :key="item.title">
                            <SidebarMenuButton asChild :tooltip="item.title">
                                <Link :href="item.url"
                                    :class="{ 'bg-primary': state == 'collapsed' && item.path === $page.url }">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroupContent>
            </SidebarGroup>
        </SidebarContent>
    </Sidebar>
</template>
<script setup lang=ts>
import Search from '@/components/Search.vue'
import { Link } from '@inertiajs/vue3'
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarRail,
    SidebarSeparator,
    SidebarFooter,

    SidebarTrigger,
} from '@/components/ui/sidebar'
import {
    ChevronsUpDown,
    GalleryVerticalEnd,
    UsersRound,
    Plus,
    Home,
    Settings,
    Bell,
    Tag,
    Terminal,
    ListCheck,
    MessageCircleQuestion,
} from 'lucide-vue-next'
import { ref } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import type { CustomBreadcrumbItem } from '@/types/BreadcrumbsType'
const props = defineProps<{
    breadcrumb?: CustomBreadcrumbItem[]
}>()

const data = {
    teams: [
        {
            name: 'coolLabs Technologies',
            logo: GalleryVerticalEnd,
            plan: 'Root',
        },

    ],
    navMain: [
        {
            title: 'Dashboard',
            icon: Home,
            url: '/next/',
        },
        {
            title: 'Notifications',
            icon: Bell,
            url: '/next/notifications',
            isDisabled: true,
        },
        {
            title: 'Tags',
            icon: Tag,
            url: '/next/tags',
            isDisabled: true,
        },
        {
            title: 'Terminal',
            icon: Terminal,
            url: '/next/terminal',
            isDisabled: true,
        },
        {
            title: 'Settings',
            icon: Settings,
            url: '/next/settings',
            isDisabled: true,
        },
        {
            title: 'Onboarding',
            icon: ListCheck,
            url: '/next/onboarding',
            isBottom: true,
            isDisabled: true,
        },
        {
            title: 'Feedback',
            icon: MessageCircleQuestion,
            url: '/next/feedback',
            isBottom: true,
            isDisabled: true,
        },

    ]
}

const activeTeam = ref(data.teams[0])
const defaultOpen = ref(true)
const cookie = document.cookie
    .split('; ')
    .find(row => row.startsWith('sidebar:state='))
defaultOpen.value = cookie?.split('=')[1] == 'false' ? false : true

function setActiveTeam(team: typeof data.teams[number]) {
    activeTeam.value = team
}
</script>

<template>
    <SidebarProvider :defaultOpen="defaultOpen">
        <Sidebar class="border-coolgray-200 border-r h-screen" collapsible="icon">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <SidebarMenuButton
                                    class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground mt-4">
                                    <div
                                        class="flex aspect-square  items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                                        <UsersRound class="size-4" />
                                    </div>
                                    <div class="grid flex-1 text-left text-sm leading-tight">
                                        <span class="truncate font-semibold">{{ activeTeam.name }}</span>
                                        <span class="truncate text-xs">{{ activeTeam.plan }}</span>
                                    </div>
                                    <ChevronsUpDown class="ml-auto" />
                                </SidebarMenuButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent class="w-[--radix-dropdown-menu-trigger-width] min-w-56 rounded-lg"
                                align="start" side="bottom" :side-offset="4">
                                <DropdownMenuLabel class="text-xs text-muted-foreground">
                                    Teams
                                </DropdownMenuLabel>
                                <DropdownMenuItem v-for="(team, index) in data.teams" :key="team.name" class="gap-2 p-2"
                                    @click="setActiveTeam(team)">
                                    <div class="flex size-6 items-center justify-center rounded-sm border">
                                        <component :is="team.logo" class="size-4 shrink-0" />
                                    </div>
                                    {{ team.name }}
                                    <DropdownMenuShortcut>⌘{{ index + 1 }}</DropdownMenuShortcut>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem class="gap-2 p-2">
                                    <div
                                        class="flex size-6 items-center justify-center rounded-md border bg-background">
                                        <Plus class="size-4" />
                                    </div>
                                    <div class="font-medium text-muted-foreground">
                                        Add team
                                    </div>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>
            <SidebarContent>
                <SidebarGroup>
                    <SidebarMenu>
                        <div class="flex flex-col h-full gap-2">
                            <div v-for="item in data.navMain" :key="item.title">
                                <SidebarMenuItem v-if="!item.isBottom">
                                    <Link :href="item.isDisabled ? '#' : item.url">
                                    <SidebarMenuButton :tooltip="item.title" as="div"
                                        :class="['hover:dark:bg-white/10', item.isDisabled ? 'opacity-50 cursor-not-allowed' : '']">
                                        <component :is="item.icon" />
                                        <span>{{ item.title }}</span>
                                    </SidebarMenuButton>
                                    </Link>
                                </SidebarMenuItem>
                            </div>
                        </div>
                    </SidebarMenu>
                </SidebarGroup>
            </SidebarContent>
            <SidebarFooter>
                <SidebarMenu>
                    <div v-for="item in data.navMain" :key="item.title">
                        <SidebarMenuItem v-if="item.isBottom">
                            <Link :href="item.isDisabled ? '#' : item.url">
                            <SidebarMenuButton :tooltip="item.title" as="div"
                                :class="['hover:dark:bg-white/10', item.isDisabled ? 'opacity-50 cursor-not-allowed' : '']">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </SidebarMenuButton>
                            </Link>
                        </SidebarMenuItem>
                    </div>
                    <!-- <SidebarMenuItem>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <SidebarMenuButton size="lg"
                                    class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground">
                                    <Avatar class="h-8 w-8 rounded-lg">
                                        <AvatarImage :src="data.user.avatar" :alt="data.user.name" />
                                        <AvatarFallback class="rounded-lg">
                                            CN
                                        </AvatarFallback>
                                    </Avatar>
                                    <div class="grid flex-1 text-left text-sm leading-tight">
                                        <span class="truncate font-semibold">{{ data.user.name }}</span>
                                        <span class="truncate text-xs">{{ data.user.email }}</span>
                                    </div>
                                    <ChevronsUpDown class="ml-auto size-4" />
                                </SidebarMenuButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent class="w-[--radix-dropdown-menu-trigger-width] min-w-56 rounded-lg"
                                side="bottom" align="end" :side-offset="4">
                                <DropdownMenuLabel class="p-0 font-normal">
                                    <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                        <Avatar class="h-8 w-8 rounded-lg">
                                            <AvatarImage :src="data.user.avatar" :alt="data.user.name" />
                                            <AvatarFallback class="rounded-lg">
                                                CN
                                            </AvatarFallback>
                                        </Avatar>
                                        <div class="grid flex-1 text-left text-sm leading-tight">
                                            <span class="truncate font-semibold">{{ data.user.name }}</span>
                                            <span class="truncate text-xs">{{ data.user.email }}</span>
                                        </div>
                                    </div>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuGroup>
                                    <DropdownMenuItem>
                                        <Sparkles />
                                        Upgrade to Pro
                                    </DropdownMenuItem>
                                </DropdownMenuGroup>
                                <DropdownMenuSeparator />
                                <DropdownMenuGroup>
                                    <DropdownMenuItem>
                                        <BadgeCheck />
                                        Account
                                    </DropdownMenuItem>
                                    <DropdownMenuItem>
                                        <CreditCard />
                                        Billing
                                    </DropdownMenuItem>
                                    <DropdownMenuItem>
                                        <Bell />
                                        Notifications
                                    </DropdownMenuItem>
                                </DropdownMenuGroup>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem>
                                    <LogOut />
                                    Log out
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </SidebarMenuItem> -->
                </SidebarMenu>
            </SidebarFooter>
            <SidebarRail />
        </Sidebar>
        <SidebarInset>
            <header class="flex shrink-0 pb-2 pt-6 bg-background pr-4 flex flex-col gap-2">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center">
                        <SidebarTrigger class="-ml-2 mr-2 " />
                        <Breadcrumb v-if="props.breadcrumb && props.breadcrumb.length > 0">
                            <BreadcrumbList>
                                <template v-for="(item, index) in props.breadcrumb" :key="index">
                                    <BreadcrumbItem>
                                        <BreadcrumbLink v-if="item.href" :href="item.href">
                                            {{ item.label }}
                                        </BreadcrumbLink>
                                        <BreadcrumbPage v-else>
                                            {{ item.label }}
                                        </BreadcrumbPage>
                                    </BreadcrumbItem>
                                    <BreadcrumbSeparator v-if="index < props.breadcrumb.length - 1">
                                    </BreadcrumbSeparator>
                                </template>
                            </BreadcrumbList>
                        </Breadcrumb>
                    </div>
                    <Search @search="(value) => $emit('search', value)" />
                </div>
                <h1 class="text-3xl font-bold">
                    <slot name="title" />
                </h1>
                <h3 class="text-sm text-muted-foreground">
                    <slot name="subtitle" />
                </h3>
            </header>
            <div class="flex flex-1 flex-col gap-4 bg-background">
                <slot />
            </div>
        </SidebarInset>
    </SidebarProvider>
</template>

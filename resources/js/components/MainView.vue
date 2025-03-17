<script setup lang=ts>
import Search from '@/components/Search.vue'
import Aside from '@/components/Aside.vue'
import { Link, usePage } from '@inertiajs/vue3'
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'
import Logo from '@/components/Logo.vue'
import { Separator } from '@/components/ui/separator'
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
    CircleX,
    Menu,
} from 'lucide-vue-next'
import { ref, watch, onMounted, onUnmounted, computed } from 'vue'
import type { CustomBreadcrumbItem } from '@/types/BreadcrumbsType'
import type { LucideIcon } from 'lucide-vue-next'
import { PageProps } from '@/types/PagePropsType'

import { Toaster } from '@/components/ui/sonner'
import {
    Drawer,
    DrawerContent,
    DrawerHeader,
    DrawerTitle,
    DrawerTrigger,
} from '@/components/ui/drawer'

interface NavItem {
    title: string
    icon: LucideIcon
    url: string
    isBottom?: boolean
    isDisabled?: boolean
}

const props = defineProps<{
    breadcrumb?: CustomBreadcrumbItem[]
    hideSearch?: boolean
    sidebarNavItems?: NavItem[]
}>()

const sidebarNavItems = computed(() => props.sidebarNavItems ?? [])

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
            isBottom: false,
            isDisabled: false,
        },
    ] as NavItem[]
}

const activeTeam = ref(data.teams[0])
const defaultOpen = ref(false)
const open = ref(false)
const cookie = document.cookie
    .split('; ')
    .find(row => row.startsWith('sidebar:state='))

if (cookie) {
    defaultOpen.value = cookie.split('=')[1] == 'false' ? false : true
} else {
    defaultOpen.value = false
}
open.value = defaultOpen.value
watch(open, (newValue) => {
    document.cookie = `sidebar:state=${newValue};max-age=${60 * 60 * 24 * 7};path=/`
})

const isMobile = ref(window.innerWidth < 768)
const isDrawerOpen = ref(false)

const handleResize = () => {
    isMobile.value = window.innerWidth < 768
}

onMounted(() => {
    window.addEventListener('resize', handleResize)
})

onUnmounted(() => {
    window.removeEventListener('resize', handleResize)
})

function setActiveTeam(team: typeof data.teams[number]) {
    activeTeam.value = team
}

const page = usePage<PageProps>()

const isActive = (url: string) => {
    const currentUrl = new URL(url, window.location.origin).pathname + (url.endsWith('/') ? '' : '/')
    const pageUrl = new URL(page.url, window.location.origin).pathname + (page.url.endsWith('/') ? '' : '/')
    return currentUrl === pageUrl
}
</script>

<template>
    <Toaster position="top-right" richColors theme="dark" :expand="true" />
    <SidebarProvider :defaultOpen="defaultOpen" v-model:open="open">
        <Sidebar v-if="!isMobile" class="border-border border-r h-screen" collapsible="icon">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <div class="flex items-center justify-between gap-2 pl-1" :class="open ? 'pt-2' : 'pt-3'">
                            <Logo :size="open ? 8 : 6" />
                            <SidebarTrigger class=" dark:text-white -ml-2 mr-0.5 hover:bg-coollabs rounded-xl"
                                v-if="open" />
                        </div>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <SidebarMenuButton
                                    class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground mt-3 py-6 hover:bg-coollabs rounded-xl">
                                    <div class="flex aspect-square items-center justify-center rounded-lg ">
                                        <UsersRound class="size-4" />
                                    </div>
                                    <div class="grid flex-1 text-left text-sm leading-tight">
                                        <span class="truncate font-semibold">{{ activeTeam.name }}</span>
                                        <span class="truncate text-xs">{{ activeTeam.plan }}</span>
                                    </div>
                                    <ChevronsUpDown class="ml-auto" />
                                </SidebarMenuButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                class="w-[--radix-dropdown-menu-trigger-width] min-w-56 rounded-xl dark:bg-coolgray-100"
                                align="start" side="bottom" :side-offset="4">
                                <DropdownMenuLabel class="text-xs text-muted-foreground">
                                    Teams
                                </DropdownMenuLabel>
                                <DropdownMenuItem v-for="(team, index) in data.teams" :key="team.name"
                                    class="gap-2 p-2 cursor-pointer rounded-xl" @click="setActiveTeam(team)">
                                    <div class="flex size-6 items-center justify-center">
                                        <component :is="team.logo" class="size-4 shrink-0" />
                                    </div>
                                    {{ team.name }}
                                    <!-- <DropdownMenuShortcut>⌘{{ index + 1 }}</DropdownMenuShortcut> -->
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem class="gap-2 p-2 rounded-xl hover:bg-coollabs">
                                    <div class="flex size-6 items-center justify-center">
                                        <Plus class="size-4" />
                                    </div>
                                    <div class="font-medium text-foreground">
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
                            <div v-for="item in data.navMain.filter(item => !item.isBottom)" :key="item.title">
                                <SidebarMenuItem>
                                    <Link :href="item.isDisabled ? '#' : item.url">
                                    <SidebarMenuButton :tooltip="item.title" as="div" :class="[
                                        'rounded-xl',
                                        item.isDisabled ? 'text-muted-foreground' :
                                            isActive(item.url) ? 'text-warning bg-coolgray-200' : 'text-muted-foreground'
                                    ]">
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
                    <div v-for="(item, index) in data.navMain.filter(item => item.isBottom)" :key="item.title">
                        <SidebarTrigger class=" dark:text-white hover:bg-coollabs ml-0.5 mb-2 rounded-xl"
                            v-if="!open && index === 0" />
                        <SidebarMenuItem>
                            <Link :href="item.isDisabled ? '#' : item.url">
                            <SidebarMenuButton :tooltip="item.title" as="div" :class="[
                                'rounded-xl',
                                item.isDisabled ? 'text-muted-foreground' :
                                    isActive(item.url) ? 'text-warning bg-coolgray-200' : 'text-muted-foreground'
                            ]">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </SidebarMenuButton>
                            </Link>
                        </SidebarMenuItem>
                    </div>
                    <SidebarTrigger v-if="!open && data.navMain.filter(item => item.isBottom).length === 0"
                        class="dark:text-white hover:bg-coollabs ml-0.5 mb-2 rounded-xl" />
                </SidebarMenu>
            </SidebarFooter>
            <SidebarRail />
        </Sidebar>
        <SidebarInset class="dark:bg-background bg-background">
            <header class="flex shrink-0 pb-2 pt-6 bg-background flex flex-col gap-2">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-4">
                        <Drawer v-if="isMobile" v-model:open="isDrawerOpen">
                            <DrawerTrigger as-child>
                                <Menu class="size-5 cursor-pointer" />
                            </DrawerTrigger>
                            <DrawerContent>
                                <div class="flex flex-col h-full">
                                    <div class="flex flex-col gap-4 p-4">
                                        <div v-for="item in data.navMain" :key="item.title">
                                            <Link :href="item.isDisabled ? '#' : item.url" class="flex items-center">
                                            <span>{{ item.title }}</span>
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            </DrawerContent>
                        </Drawer>
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
                    <Search v-if="!props.hideSearch" @search="(value) => $emit('search', value)" />
                </div>
            </header>
            <main class="flex flex-1 flex-col gap-4 mb-24">
                <h1 v-if="$slots.title" class="text-3xl font-bold pt-4">
                    <slot name="title" />
                </h1>
                <h3 v-if="$slots.subtitle" class="text-sm text-muted-foreground">
                    <slot name="subtitle" />
                </h3>
                <div :class="$slots.title || $slots.subtitle ? 'pt-4' : ''">
                    <div v-if="props.sidebarNavItems && props.sidebarNavItems.length > 0"
                        class="flex flex-col space-y-8 lg:flex-row lg:space-x-12 lg:space-y-0">
                        <Aside :sidebarNavItems="sidebarNavItems" />
                        <div class="flex-1">
                            <slot name="main" />
                        </div>
                    </div>
                    <div v-else>
                        <slot />
                    </div>
                </div>
            </main>
        </SidebarInset>
    </SidebarProvider>
</template>

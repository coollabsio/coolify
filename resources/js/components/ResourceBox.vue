<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { Server, GitBranch, Map, BriefcaseBusiness, Plus, Earth, Code, Database } from 'lucide-vue-next'
import { computed } from 'vue';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card'
import { Environment } from '@/types/EnvironmentType';
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet'
const props = defineProps<{
    type: 'project' | 'server' | 'source' | 'destination' | 'environment' | 'application' | 'postgresql' | 'service';
    href: string;
    name: string;
    description?: string;
    new?: boolean;
    environments?: Environment[];
}>();

const isNew = computed(() => props.new)
const environments = computed(() => props.environments)
</script>

<template>
    <div v-if="isNew"
        class="flex rounded-xl cursor-pointer h-24 hover:dark:border-coollabs  border border-transparent transition-all group">

        <Sheet>
            <SheetTrigger as-child>
                <div class="flex gap-2 items-center justify-center w-full p-2">
                    <Plus :size="20" class="text-muted-foreground/60 group-hover:dark:text-white" />
                    <div class="text-sm font-bold text-muted-foreground/60 group-hover:dark:text-white">
                        New {{ type }}
                    </div>
                </div>
            </SheetTrigger>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>New Project</SheetTitle>
                    <SheetDescription>
                        Create a new project.
                    </SheetDescription>
                </SheetHeader>
                <div class="grid gap-4 py-4">
                    <div class="grid grid-cols-4 items-center gap-4">
                        <Label for="name" class="text-right">
                            Name
                        </Label>
                        <Input id="name" default-value="Pedro Duarte" class="col-span-3" />
                    </div>
                    <div class="grid grid-cols-4 items-center gap-4">
                        <Label for="username" class="text-right">
                            Description
                        </Label>
                        <Input id="username" default-value="@peduarte" class="col-span-3" />
                    </div>
                </div>
                <SheetFooter>
                    <SheetClose as-child>
                        <Button type="submit">
                            Save changes
                        </Button>
                    </SheetClose>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    </div>
    <div v-else>
        <HoverCard v-if="type === 'project' && environments" :open-delay="100" :close-delay="100">
            <HoverCardTrigger>
                <Link prefetch :href="href"
                    class="flex rounded-r-xl bg-coolgray-100 border dark:border-black cursor-pointer h-24 group">
                <div class=" text-xs text-muted-foreground group-hover:dark:text-white font-bold h-full bg-coolgray-200 p-2
            group-hover:bg-coollabs rounded-l-xl transition-all">
                    <BriefcaseBusiness :size="20" v-if="type === 'project'" />
                </div>
                <div class="flex flex-col p-2">
                    <div class="text-sm font-bold text-foreground">{{ name }}</div>
                    <p class="text-xs text-muted-foreground group-hover:dark:text-white font-bold">{{ description
                        }}</p>
                </div>
                </Link>
            </HoverCardTrigger>
            <HoverCardContent class="w-64 p-2 rounded-xl dark:bg-coolgray-100 shadow-xl dark:border-black"
                :side-offset="5" align="start">
                <h3 class="text-sm font-bold text-foreground pb-2 px-2">Environments</h3>
                <div v-for="environment in environments" :key="environment.uuid" class="flex flex-col gap-2 text-xs">
                    <Link class="hover:dark:bg-coolgray-300 p-2 rounded-md flex gap-2 items-center"
                        :href="route('next_environment', { project_uuid: environment.project_uuid, environment_uuid: environment.uuid })">
                    <Earth :size="16" class="text-muted-foreground/60" />
                    {{ environment.name }}
                    </Link>
                </div>
            </HoverCardContent>
        </HoverCard>
        <Link v-else prefetch :href="href"
            class="flex rounded-r-xl bg-coolgray-100 border dark:border-black cursor-pointer h-24 group">
        <div class=" text-xs text-muted-foreground group-hover:dark:text-white font-bold h-full bg-coolgray-200 p-2
            group-hover:bg-coollabs rounded-l-xl transition-all">
            <BriefcaseBusiness :size="20" v-if="type === 'project'" />
            <Server :size="20" v-else-if="type === 'server'" />
            <GitBranch :size="20" v-else-if="type === 'source'" />
            <Map :size="20" v-else-if="type === 'destination'" />
            <Earth :size="20" v-else-if="type === 'environment'" />
            <Code :size="20" v-else-if="type === 'application'" />
            <Database :size="20" v-else-if="type === 'postgresql'" />
            <Server :size="20" v-else-if="type === 'service'" />
        </div>
        <div class="flex flex-col p-2">
            <div class="text-sm font-bold text-foreground">{{ name }}</div>
            <p class="text-xs text-muted-foreground group-hover:dark:text-white font-bold">{{ description
                }}</p>
        </div>
        </Link>
    </div>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { ref, computed } from 'vue';
import { createReusableTemplate, useMediaQuery } from '@vueuse/core'
import { Plus, Earth, Info } from 'lucide-vue-next'
import { AutoForm } from '@/components/ui/auto-form'
import * as z from 'zod'
import { toTypedSchema } from '@vee-validate/zod';
import { useForm } from 'vee-validate';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card'
import { Button } from '@/components/ui/button'
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogFooter,
    DialogTitle,
    DialogClose,
    DialogTrigger,
} from '@/components/ui/dialog'
import {
    Drawer,
    DrawerContent,
    DrawerHeader,
    DrawerTitle,
    DrawerTrigger,
} from '@/components/ui/drawer'
import ResourceBoxLink from './ResourceBoxLink.vue';
import { Environment } from '@/types/EnvironmentType';

const props = defineProps<{
    type: 'project' | 'server' | 'source' | 'destination' | 'environment' | 'application' | 'standalone-postgresql' | 'standalone-mysql' | 'service';
    href: string;
    name: string;
    description?: string;
    new?: boolean;
    environments?: Environment[];
}>();

const [UseTemplate, GridForm] = createReusableTemplate()
const isDesktop = useMediaQuery('(min-width: 768px)')

const isOpen = ref(false)

const isNew = computed(() => props.new)
const environments = computed(() => props.environments)

const schema = z.object({
    name: z.string().min(1, { message: 'Name is required' }).max(255, { message: 'Name must be less than 255 characters' }).describe('Name'),
    description: z.string().max(255, { message: 'Description must be less than 255 characters' }).describe('Description').optional(),
})
const form = useForm({
    validationSchema: toTypedSchema(schema),
})
function onSubmit(values: Record<string, any>) {
    console.log(values)
}

function handleClose() {
    form.resetForm()
    isOpen.value = false
}
</script>

<template>
    <UseTemplate>
        <AutoForm class="space-y-2 py-4" :class="isDesktop ? '' : 'p-4'" :schema="schema" :form="form"
            @submit="onSubmit">
            <div class="flex gap-2 items-center p-2 bg-primary/50 border border-primary rounded-xl">
                <Info :size="16" class="text-muted-foreground group-hover:dark:text-white" />
                <div class="text-sm text-foreground">
                    This {{ type }} will have a default <span class="font-bold">production</span> environment.
                </div>
            </div>
            <DialogFooter>
                <div class="flex justify-between gap-2 w-full">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary" @click="handleClose">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button type="submit" :class="isDesktop ? '' : 'w-full'">
                        Create
                    </Button>
                </div>
            </DialogFooter>
        </AutoForm>
    </UseTemplate>
    <div v-if="isNew"
        class="flex rounded-xl cursor-pointer h-24 hover:dark:border-primary border border-transparent transition-all group">
        <Dialog v-if="isDesktop" v-model:open="isOpen">
            <DialogTrigger as-child>
                <div class="flex gap-2 items-center justify-center w-full p-2">
                    <Plus :size="20" class="text-muted-foreground/60 group-hover:dark:text-white" />
                    <div class="text-sm font-bold text-muted-foreground/60 group-hover:dark:text-white">
                        New {{ type }}
                    </div>
                </div>
            </DialogTrigger>
            <DialogContent hide-close>
                <DialogHeader>
                    <DialogTitle>New {{ type }}</DialogTitle>
                </DialogHeader>
                <GridForm />
            </DialogContent>
        </Dialog>
        <Drawer v-else v-model:open="isOpen">
            <DrawerTrigger as-child>
                <div class="flex gap-2 items-center justify-center w-full p-2">
                    <Plus :size="20" class="text-muted-foreground/60 group-hover:dark:text-white" />
                    <div class="text-sm font-bold text-muted-foreground/60 group-hover:dark:text-white">
                        New {{ type }}
                    </div>
                </div>
            </DrawerTrigger>
            <DrawerContent>
                <DrawerHeader>
                    <DrawerTitle>New {{ type }}</DrawerTitle>
                </DrawerHeader>
                <GridForm />
            </DrawerContent>
        </Drawer>
    </div>
    <div v-else>
        <HoverCard v-if="type === 'project' && environments" :open-delay="0" :close-delay="0">
            <HoverCardTrigger>
                <ResourceBoxLink :type="type" :href="href" :name="name" :description="description" />
            </HoverCardTrigger>
            <HoverCardContent class="w-64 p-2 rounded dark:bg-coolgray-100" :side-offset="5" align="start">
                <h3 class="text-sm font-bold text-foreground pb-2 px-2">Environments</h3>
                <div v-for="environment in environments" :key="environment.uuid"
                    class="flex flex-col gap-2 text-xs group">
                    <Link
                        class="hover:dark:bg-primary p-2 rounded-xl flex gap-2 items-center text-muted-foreground hover:text-white"
                        :href="route('next_environment', { project_uuid: environment.project_uuid, environment_uuid: environment.uuid })">
                    <Earth :size="16" class="text-muted-foreground/40 group-hover:dark:text-white" />
                    {{ environment.name }}
                    </Link>
                </div>
            </HoverCardContent>
        </HoverCard>
        <div v-else class="w-full rounded dark:bg-coolgray-100">
            <ResourceBoxLink :type="type" :href="href" :name="name" :description="description" />
        </div>
    </div>
</template>

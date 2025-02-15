<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { Plus, Earth, Info } from 'lucide-vue-next'
import { computed } from 'vue';
import { AutoForm } from '@/components/ui/auto-form'
import * as z from 'zod'
import { toTypedSchema } from '@vee-validate/zod';
import { useForm } from 'vee-validate';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card'
import { Environment } from '@/types/EnvironmentType';
import { Button } from '@/components/ui/button'
const props = defineProps<{
    type: 'project' | 'server' | 'source' | 'destination' | 'environment' | 'application' | 'standalone-postgresql' | 'standalone-mysql' | 'service';
    href: string;
    name: string;
    description?: string;
    new?: boolean;
    environments?: Environment[];
}>();
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog'
import ResourceBoxLink from './ResourceBoxLink.vue';
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
</script>

<template>
    <div v-if="isNew"
        class="flex rounded-xl cursor-pointer h-24 hover:dark:border-coollabs border border-transparent transition-all group">
        <Dialog>
            <DialogTrigger as-child>
                <div class="flex gap-2 items-center justify-center w-full p-2">
                    <Plus :size="20" class="text-muted-foreground/60 group-hover:dark:text-white" />
                    <div class="text-sm font-bold text-muted-foreground/60 group-hover:dark:text-white">
                        New {{ type }}
                    </div>
                </div>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New {{ type }}</DialogTitle>
                </DialogHeader>
                <AutoForm class="space-y-2" :schema="schema" :form="form" @submit="onSubmit">
                    <div class="flex gap-2 items-center p-2 bg-coollabs/50 border border-coollabs rounded-xl">
                        <Info :size="16" class="text-muted-foreground group-hover:dark:text-white" />
                        <div class="text-sm text-foreground ">
                            This {{ type }} will have a default <span class="font-bold">production</span> environment.
                        </div>
                    </div>
                    <div>
                        <Button type="submit" class="mt-4">
                            Create
                        </Button>
                    </div>
                </AutoForm>
            </DialogContent>
        </Dialog>
    </div>
    <div v-else>
        <HoverCard v-if="type === 'project' && environments" :open-delay="100" :close-delay="100">
            <HoverCardTrigger>
                <ResourceBoxLink :type="type" :href="href" :name="name" :description="description" />
            </HoverCardTrigger>
            <HoverCardContent class="w-64 p-2 rounded dark:bg-coolgray-100" :side-offset="5" align="start">
                <h3 class="text-sm font-bold text-foreground pb-2 px-2">Environments</h3>
                <div v-for="environment in environments" :key="environment.uuid"
                    class="flex flex-col gap-2 text-xs group">
                    <Link
                        class="hover:dark:bg-coollabs p-2 rounded-xl flex gap-2 items-center text-muted-foreground hover:text-white"
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

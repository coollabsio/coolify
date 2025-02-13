<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { Server, GitBranch, Map, BriefcaseBusiness, Plus } from 'lucide-vue-next'
import { computed } from 'vue';

const props = defineProps<{
    type: 'project' | 'server' | 'source' | 'destination';
    href: string;
    name: string;
    description?: string;
    new?: boolean;
}>();

const isNew = computed(() => props.new)
</script>

<template>
    <div v-if="isNew"
        class="flex rounded-xl cursor-pointer h-24 hover:dark:border-coollabs  border border-transparent transition-all group">
        <div class="flex gap-2 items-center justify-center w-full p-2">
            <Plus :size="20" class="text-muted-foreground/60 group-hover:dark:text-white" />
            <div class="text-sm font-bold text-muted-foreground/60 group-hover:dark:text-white">
                New {{ type }}
            </div>
        </div>
    </div>
    <Link v-else :href="href"
        class="flex rounded-r-xl bg-coolgray-100 border dark:border-black cursor-pointer h-24 group">
    <div class=" text-xs text-muted-foreground group-hover:dark:text-white font-bold h-full bg-coolgray-200 p-2
        group-hover:bg-coollabs rounded-l-xl transition-all">
        <BriefcaseBusiness :size="20" v-if="type === 'project'" />
        <Server :size="20" v-else-if="type === 'server'" />
        <GitBranch :size="20" v-else-if="type === 'source'" />
        <Map :size="20" v-else-if="type === 'destination'" />
    </div>
    <div class="flex flex-col p-2">
        <div class="text-sm font-bold text-foreground">{{ name }}</div>
        <p class="text-xs text-muted-foreground group-hover:dark:text-white font-bold">{{ description
            }}</p>
    </div>
    </Link>
</template>

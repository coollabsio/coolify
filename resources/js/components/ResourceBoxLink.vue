<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { Server, GitBranch, Map, BriefcaseBusiness, Earth, Code, Database, Radiation } from 'lucide-vue-next'
defineProps<{
    type: 'project' | 'server' | 'source' | 'destination' | 'environment' | 'application' | 'standalone-postgresql' | 'standalone-mysql' | 'service';
    href: string;
    name: string;
    description?: string;
}>();

</script>

<template>
    <Link prefetch :href="href" class="flex rounded-xl bg-coolgray-300 cursor-pointer h-24 group">
    <div class="text-xs text-muted-foreground group-hover:dark:text-white font-bold h-full bg-coolgray-200 p-2
                    group-hover:bg-coollabs rounded-xl transition-all">
        <BriefcaseBusiness :size="20" v-if="type === 'project'" />
        <Server :size="20" v-else-if="type === 'server'" />
        <GitBranch :size="20" v-else-if="type === 'source'" />
        <Map :size="20" v-else-if="type === 'destination'" />
        <Earth :size="20" v-else-if="type === 'environment'" />
        <Code :size="20" v-else-if="type === 'application'" />
        <Database :size="20" v-else-if="type.startsWith('standalone-')" />
        <Radiation :size="20" v-else-if="type === 'service'" />
    </div>
    <div class="flex flex-col p-2">
        <div class="text-sm font-bold text-foreground">{{ name }}</div>
        <p class="text-xs text-muted-foreground">{{ description
            }}</p>
    </div>
    </Link>
</template>

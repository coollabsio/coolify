<script lang="ts" setup>
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { Link } from '@inertiajs/vue3'

const props = defineProps<{
    href: string
    class?: HTMLAttributes['class']
}>()
</script>

<template>
    <Link prefetch :href="href"
        :class="cn('text-muted-foreground dark:hover:text-foreground cursor-pointer', props.class)">
    <slot />
    </Link>
</template>

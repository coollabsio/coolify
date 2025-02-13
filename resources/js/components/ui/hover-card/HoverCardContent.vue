<script setup lang="ts">
import { cn } from '@/lib/utils'
import {
    HoverCardContent,
    type HoverCardContentProps,
    HoverCardPortal,
    useForwardProps,
} from 'radix-vue'
import { computed, type HTMLAttributes } from 'vue'

const props = withDefaults(
    defineProps<HoverCardContentProps & { class?: HTMLAttributes['class'] }>(),
    {
        sideOffset: 4,
    },
)

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props

    return delegated
})

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
    <HoverCardPortal>
        <HoverCardContent v-bind="forwardedProps" :class="cn(
            'z-50 w-64 rounded-md border border-neutral-200 bg-white p-4 text-neutral-950 shadow-md outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-50',
            props.class,
        )
            ">
            <slot />
        </HoverCardContent>
    </HoverCardPortal>
</template>

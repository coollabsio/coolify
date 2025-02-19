<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { useVModel } from '@vueuse/core'

const props = defineProps<{
    defaultValue?: string | number
    modelValue?: string | number
    class?: HTMLAttributes['class']
    size?: 'sm' | 'md' | 'lg' | 'xs'
}>()

const emits = defineEmits<{
    (e: 'update:modelValue', payload: string | number): void
}>()

const modelValue = useVModel(props, 'modelValue', emits, {
    passive: true,
    defaultValue: props.defaultValue,
})
</script>

<template>
    <input v-model="modelValue" :class="cn('flex h-10 w-full rounded-xl border border-l-4  border-input  bg-input-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus:outline-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 dark:outline-coolgray-200 shadow', props.class, {
        'h-8': props.size === 'xs',
        'h-10': props.size === 'sm',
        'h-12': props.size === 'md',
        'h-14': props.size === 'lg',
    })">
</template>

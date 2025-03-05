<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { inputType } from '@/lib/custom'
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
    <input v-model="modelValue" :class="cn(inputType, 'flex h-9 file:border-0 file:bg-transparent file:text-sm file:font-medium', props.class, {
        'h-8': props.size === 'xs',
        'h-10': props.size === 'sm',
        'h-12': props.size === 'md',
        'h-14': props.size === 'lg',
    })">
</template>

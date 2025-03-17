<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { Primitive, type PrimitiveProps } from 'radix-vue'
import { type ButtonVariants, buttonVariants } from '.'
import { Loader2 } from 'lucide-vue-next'


interface Props extends PrimitiveProps {
    variant?: ButtonVariants['variant']
    size?: ButtonVariants['size']
    class?: HTMLAttributes['class']
    loading?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    as: 'button',
    loading: false
})
</script>

<template>
    <Primitive :as="as" :as-child="asChild" :disabled="loading"
        :class="cn(buttonVariants({ variant, size }), 'cursor-pointer', props.class)">
        <slot />
        <Loader2 class="w-4 h-4 ml-2 animate-spin" v-if="loading" />
    </Primitive>
</template>

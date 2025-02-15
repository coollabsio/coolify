<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { Primitive, type PrimitiveProps } from 'radix-vue'
import { type ButtonVariants, buttonVariants } from '.'


interface Props extends PrimitiveProps {
    variant?: ButtonVariants['variant']
    size?: ButtonVariants['size']
    class?: HTMLAttributes['class']
}

const props = withDefaults(defineProps<Props>(), {
    as: 'button',
})
</script>

<template>
    <Primitive :as="as" :as-child="asChild"
        :class="cn(buttonVariants({ variant, size }), 'cursor-pointer', props.class)">
        <slot />
    </Primitive>
</template>

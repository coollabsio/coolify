<script setup lang="ts">
import type { ToastRootEmits } from 'radix-vue'
import type { ToastProps } from '.'
import { cn } from '@/lib/utils'
import { ToastRoot, useForwardPropsEmits } from 'radix-vue'
import { computed } from 'vue'
import { toastVariants } from '.'

const props = defineProps<ToastProps>()

const emits = defineEmits<ToastRootEmits>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <ToastRoot v-bind="forwarded" :class="cn(toastVariants({ variant }), props.class)" @update:open="onOpenChange">
    <slot />
  </ToastRoot>
</template>

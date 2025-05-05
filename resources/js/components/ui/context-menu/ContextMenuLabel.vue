<script setup lang="ts">
import { cn } from '@/lib/utils'
import { ContextMenuLabel, type ContextMenuLabelProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

const props = defineProps<ContextMenuLabelProps & { class?: HTMLAttributes['class'], inset?: boolean }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})
</script>

<template>
  <ContextMenuLabel
    data-slot="context-menu-label"
    :data-inset="inset ? '' : undefined"
    v-bind="delegatedProps"
    :class="cn('text-foreground px-2 py-1.5 text-sm font-medium data-[inset]:pl-8', props.class)"
  >
    <slot />
  </ContextMenuLabel>
</template>

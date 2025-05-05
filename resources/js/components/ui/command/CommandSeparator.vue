<script setup lang="ts">
import type { SeparatorProps } from 'reka-ui'
import { cn } from '@/lib/utils'
import { Separator } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

const props = defineProps<SeparatorProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})
</script>

<template>
  <Separator
    data-slot="command-separator"
    v-bind="delegatedProps"
    :class="cn('bg-border -mx-1 h-px', props.class)"
  >
    <slot />
  </Separator>
</template>

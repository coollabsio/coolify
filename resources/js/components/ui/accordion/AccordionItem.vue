<script setup lang="ts">
import type { AccordionItemProps } from 'radix-vue'
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { AccordionItem, useForwardProps } from 'radix-vue'
import { computed } from 'vue'

const props = defineProps<AccordionItemProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <AccordionItem
    v-bind="forwardedProps"
    :class="cn('border-b', props.class)"
  >
    <slot />
  </AccordionItem>
</template>

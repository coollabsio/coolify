<script setup lang="ts">
import { cn } from '@/lib/utils'
import { AccordionItem, type AccordionItemProps, useForwardProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

const props = defineProps<AccordionItemProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <AccordionItem
    data-slot="accordion-item"
    v-bind="forwardedProps"
    :class="cn('border-b last:border-b-0', props.class)"
  >
    <slot />
  </AccordionItem>
</template>

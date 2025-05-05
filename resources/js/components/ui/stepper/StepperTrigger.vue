<script lang="ts" setup>
import type { StepperTriggerProps } from 'reka-ui'
import { cn } from '@/lib/utils'
import { StepperTrigger, useForwardProps } from 'reka-ui'

import { computed, type HTMLAttributes } from 'vue'

const props = defineProps<StepperTriggerProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwarded = useForwardProps(delegatedProps)
</script>

<template>
  <StepperTrigger
    v-bind="forwarded"
    :class="cn('p-1 flex flex-col items-center text-center gap-1 rounded-md', props.class)"
  >
    <slot />
  </StepperTrigger>
</template>

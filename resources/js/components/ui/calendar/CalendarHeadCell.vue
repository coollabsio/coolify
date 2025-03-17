<script lang="ts" setup>
import type { CalendarHeadCellProps } from 'radix-vue'
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { CalendarHeadCell, useForwardProps } from 'radix-vue'
import { computed } from 'vue'

const props = defineProps<CalendarHeadCellProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <CalendarHeadCell :class="cn('w-9 rounded-md text-[0.8rem] font-normal text-neutral-500 dark:text-neutral-400', props.class)" v-bind="forwardedProps">
    <slot />
  </CalendarHeadCell>
</template>

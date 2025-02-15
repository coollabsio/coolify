<script lang="ts" setup>
import type { CalendarCellTriggerProps } from 'radix-vue'
import type { HTMLAttributes } from 'vue'
import { buttonVariants } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { CalendarCellTrigger, useForwardProps } from 'radix-vue'
import { computed } from 'vue'

const props = defineProps<CalendarCellTriggerProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <CalendarCellTrigger
    :class="cn(
      buttonVariants({ variant: 'ghost' }),
      'h-9 w-9 p-0 font-normal',
      '[&[data-today]:not([data-selected])]:bg-neutral-100 [&[data-today]:not([data-selected])]:text-neutral-900 dark:[&[data-today]:not([data-selected])]:bg-neutral-800 dark:[&[data-today]:not([data-selected])]:text-neutral-50',
      // Selected
      'data-[selected]:bg-neutral-900 data-[selected]:text-neutral-50 data-[selected]:opacity-100 data-[selected]:hover:bg-neutral-900 data-[selected]:hover:text-neutral-50 data-[selected]:focus:bg-neutral-900 data-[selected]:focus:text-neutral-50 dark:data-[selected]:bg-neutral-50 dark:data-[selected]:text-neutral-900 dark:data-[selected]:hover:bg-neutral-50 dark:data-[selected]:hover:text-neutral-900 dark:data-[selected]:focus:bg-neutral-50 dark:data-[selected]:focus:text-neutral-900',
      // Disabled
      'data-[disabled]:text-neutral-500 data-[disabled]:opacity-50 dark:data-[disabled]:text-neutral-400',
      // Unavailable
      'data-[unavailable]:text-neutral-50 data-[unavailable]:line-through dark:data-[unavailable]:text-neutral-50',
      // Outside months
      'data-[outside-view]:text-neutral-500 data-[outside-view]:opacity-50 [&[data-outside-view][data-selected]]:bg-neutral-100/50 [&[data-outside-view][data-selected]]:text-neutral-500 [&[data-outside-view][data-selected]]:opacity-30 dark:data-[outside-view]:text-neutral-400 dark:[&[data-outside-view][data-selected]]:bg-neutral-800/50 dark:[&[data-outside-view][data-selected]]:text-neutral-400',
      props.class,
    )"
    v-bind="forwardedProps"
  >
    <slot />
  </CalendarCellTrigger>
</template>

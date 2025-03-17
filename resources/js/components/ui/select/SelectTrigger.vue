<script setup lang="ts">
import type { SelectTriggerProps } from 'radix-vue'
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { ChevronDown } from 'lucide-vue-next'
import { SelectIcon, SelectTrigger, useForwardProps } from 'radix-vue'
import { computed } from 'vue'

const props = defineProps<SelectTriggerProps & { class?: HTMLAttributes['class'], isFieldDirty?: boolean }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <SelectTrigger v-bind="forwardedProps" :class="cn(
    'flex h-9 w-full items-center justify-between rounded-xl border border-l-4 bg-white px-3 py-2 text-sm ring-offset-white data-[placeholder]:text-neutral-500 focus:outline-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-transparent disabled:cursor-not-allowed disabled:opacity-50 [&>span]:truncate text-start  dark:bg-input-background dark:ring-offset-neutral-950 dark:data-[placeholder]:text-neutral-400 ',
    props.class,
    {
      'border-warning': props.isFieldDirty,
      'border-neutral-200 dark:border-neutral-800 ': !props.isFieldDirty,
    }
  )">
    <slot />
    <SelectIcon as-child>
      <ChevronDown class="w-4 h-4 opacity-50 shrink-0" />
    </SelectIcon>
  </SelectTrigger>
</template>

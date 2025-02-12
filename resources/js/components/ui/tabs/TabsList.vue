<script setup lang="ts">
import { cn } from '@/lib/utils'
import { TabsList, type TabsListProps } from 'radix-vue'
import { computed, type HTMLAttributes } from 'vue'

const props = defineProps<TabsListProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})
</script>

<template>
  <TabsList
    v-bind="delegatedProps"
    :class="cn(
      'inline-flex items-center justify-center rounded-md bg-neutral-100 p-1 text-neutral-500 dark:bg-neutral-800 dark:text-foreground',
      props.class,
    )"
  >
    <slot />
  </TabsList>
</template>

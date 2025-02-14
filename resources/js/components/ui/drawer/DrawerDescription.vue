<script lang="ts" setup>
import type { DrawerDescriptionProps } from 'vaul-vue'
import { cn } from '@/lib/utils'
import { DrawerDescription } from 'vaul-vue'
import { computed, type HtmlHTMLAttributes } from 'vue'

const props = defineProps<DrawerDescriptionProps & { class?: HtmlHTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})
</script>

<template>
  <DrawerDescription v-bind="delegatedProps" :class="cn('text-sm text-neutral-500 dark:text-neutral-400', props.class)">
    <slot />
  </DrawerDescription>
</template>

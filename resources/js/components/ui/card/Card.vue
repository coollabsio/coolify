<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <div :class="cn(
    'rounded-xl border border-neutral-200 bg-white text-muted-foreground shadow-sm dark:border-neutral-800 dark:bg-background dark:text-foreground',
    props.class,
  )
    ">
    <slot />
  </div>
</template>

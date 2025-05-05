<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <div
    data-slot="alert-title"
    :class="cn('col-start-2 line-clamp-1 min-h-4 font-medium tracking-tight', props.class)"
  >
    <slot />
  </div>
</template>

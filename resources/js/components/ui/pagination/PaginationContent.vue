<script setup lang="ts">
import { cn } from '@/lib/utils'
import { PaginationList, type PaginationListProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

const props = defineProps<PaginationListProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})
</script>

<template>
  <PaginationList
    v-slot="slotProps"
    data-slot="pagination-content"
    v-bind="delegatedProps"
    :class="cn('flex flex-row items-center gap-1', props.class)"
  >
    <slot v-bind="slotProps" />
  </PaginationList>
</template>

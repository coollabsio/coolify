<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { reactiveOmit } from '@vueuse/core'
import { MenubarLabel, type MenubarLabelProps } from 'reka-ui'

const props = defineProps<MenubarLabelProps & { class?: HTMLAttributes['class'], inset?: boolean }>()
const delegatedProps = reactiveOmit(props, 'class', 'inset')
</script>

<template>
  <MenubarLabel
    :data-inset="inset ? '' : undefined"
    v-bind="delegatedProps"
    :class="cn('px-2 py-1.5 text-sm font-medium data-[inset]:pl-8', props.class)"
  >
    <slot />
  </MenubarLabel>
</template>

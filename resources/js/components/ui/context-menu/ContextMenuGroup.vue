<script setup lang="ts">
import { ContextMenuGroup, type ContextMenuGroupProps } from 'reka-ui'

const props = defineProps<ContextMenuGroupProps>()
</script>

<template>
  <ContextMenuGroup
    data-slot="context-menu-group"
    v-bind="props"
  >
    <slot />
  </ContextMenuGroup>
</template>

<script setup lang="ts">
import { HoverCardRoot, type HoverCardRootEmits, type HoverCardRootProps, useForwardPropsEmits } from 'reka-ui'

const props = defineProps<HoverCardRootProps>()
const emits = defineEmits<HoverCardRootEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <HoverCardRoot
    data-slot="hover-card"
    v-bind="forwarded"
  >
    <slot />
  </HoverCardRoot>
</template>

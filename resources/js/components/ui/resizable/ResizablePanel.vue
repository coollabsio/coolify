<script setup lang="ts">
import type { SplitterPanelEmits, SplitterPanelProps } from 'reka-ui'
import { SplitterPanel, useForwardPropsEmits } from 'reka-ui'

const props = defineProps<SplitterPanelProps>()
const emits = defineEmits<SplitterPanelEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <SplitterPanel
    data-slot="resizable-panel"
    v-bind="forwarded"
  >
    <slot />
  </SplitterPanel>
</template>

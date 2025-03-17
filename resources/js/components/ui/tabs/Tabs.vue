<script setup lang="ts">
import type { TabsRootEmits, TabsRootProps } from 'radix-vue'
import { TabsRoot, useForwardPropsEmits } from 'radix-vue'

const props = defineProps<TabsRootProps>()
const emits = defineEmits<TabsRootEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <TabsRoot v-bind="forwarded">
    <slot />
  </TabsRoot>
</template>

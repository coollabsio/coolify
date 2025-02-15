<script setup lang="ts">
import type { AccordionRootEmits, AccordionRootProps } from 'radix-vue'
import {
  AccordionRoot,

  useForwardPropsEmits,
} from 'radix-vue'

const props = defineProps<AccordionRootProps>()
const emits = defineEmits<AccordionRootEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <AccordionRoot v-bind="forwarded">
    <slot />
  </AccordionRoot>
</template>

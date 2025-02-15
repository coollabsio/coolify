<script setup lang="ts">
import type { SelectValueProps } from 'radix-vue'
import { SelectValue } from 'radix-vue'

const props = defineProps<SelectValueProps>()
</script>

<template>
  <SelectValue v-bind="props">
    <slot />
  </SelectValue>
</template>

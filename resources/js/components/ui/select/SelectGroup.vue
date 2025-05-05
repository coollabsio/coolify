<script setup lang="ts">
import { SelectGroup, type SelectGroupProps } from 'reka-ui'

const props = defineProps<SelectGroupProps>()
</script>

<template>
  <SelectGroup
    data-slot="select-group"
    v-bind="props"
  >
    <slot />
  </SelectGroup>
</template>

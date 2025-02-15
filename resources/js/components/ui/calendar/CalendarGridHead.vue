<script lang="ts" setup>
import type { CalendarGridHeadProps } from 'radix-vue'
import { CalendarGridHead } from 'radix-vue'

const props = defineProps<CalendarGridHeadProps>()
</script>

<template>
  <CalendarGridHead v-bind="props">
    <slot />
  </CalendarGridHead>
</template>

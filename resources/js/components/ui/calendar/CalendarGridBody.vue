<script lang="ts" setup>
import type { CalendarGridBodyProps } from 'radix-vue'
import { CalendarGridBody } from 'radix-vue'

const props = defineProps<CalendarGridBodyProps>()
</script>

<template>
  <CalendarGridBody v-bind="props">
    <slot />
  </CalendarGridBody>
</template>

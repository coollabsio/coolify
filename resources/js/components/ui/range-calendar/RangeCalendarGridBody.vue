<script lang="ts" setup>
import { RangeCalendarGridBody, type RangeCalendarGridBodyProps } from 'reka-ui'

const props = defineProps<RangeCalendarGridBodyProps>()
</script>

<template>
  <RangeCalendarGridBody
    data-slot="range-calendar-grid-body"
    v-bind="props"
  >
    <slot />
  </RangeCalendarGridBody>
</template>

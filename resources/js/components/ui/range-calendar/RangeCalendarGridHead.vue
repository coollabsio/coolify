<script lang="ts" setup>
import { RangeCalendarGridHead, type RangeCalendarGridHeadProps } from 'reka-ui'

const props = defineProps<RangeCalendarGridHeadProps>()
</script>

<template>
  <RangeCalendarGridHead
    data-slot="range-calendar-grid-head"
    v-bind="props"
  >
    <slot />
  </RangeCalendarGridHead>
</template>

<script setup lang="ts">
import { type AlertDialogEmits, type AlertDialogProps, AlertDialogRoot, useForwardPropsEmits } from 'reka-ui'

const props = defineProps<AlertDialogProps>()
const emits = defineEmits<AlertDialogEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <AlertDialogRoot data-slot="alert-dialog" v-bind="forwarded">
    <slot />
  </AlertDialogRoot>
</template>

<script setup lang="ts">
import type { DialogRootEmits, DialogRootProps } from 'reka-ui'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useForwardPropsEmits } from 'reka-ui'
import Command from './Command.vue'

const props = withDefaults(defineProps<DialogRootProps & {
  title?: string
  description?: string
}>(), {
  title: 'Command Palette',
  description: 'Search for a command to run...',
})
const emits = defineEmits<DialogRootEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <Dialog v-bind="forwarded">
    <DialogHeader class="sr-only">
      <DialogTitle>{{ title }}</DialogTitle>
      <DialogDescription>{{ description }}</DialogDescription>
    </DialogHeader>
    <DialogContent class="overflow-hidden p-0 ">
      <Command>
        <slot />
      </Command>
    </DialogContent>
  </Dialog>
</template>

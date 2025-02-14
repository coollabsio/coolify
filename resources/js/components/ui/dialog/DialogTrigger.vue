<script setup lang="ts">
import { DialogTrigger, type DialogTriggerProps } from 'radix-vue'

const props = defineProps<DialogTriggerProps>()
</script>

<template>
  <DialogTrigger v-bind="props">
    <slot />
  </DialogTrigger>
</template>

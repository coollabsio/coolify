<script setup lang="ts">
import { MenubarGroup, type MenubarGroupProps } from 'radix-vue'

const props = defineProps<MenubarGroupProps>()
</script>

<template>
  <MenubarGroup v-bind="props">
    <slot />
  </MenubarGroup>
</template>

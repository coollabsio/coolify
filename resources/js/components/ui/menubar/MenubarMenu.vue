<script setup lang="ts">
import { MenubarMenu, type MenubarMenuProps } from 'radix-vue'

const props = defineProps<MenubarMenuProps>()
</script>

<template>
  <MenubarMenu v-bind="props">
    <slot />
  </MenubarMenu>
</template>

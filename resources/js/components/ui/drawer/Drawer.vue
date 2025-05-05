<script lang="ts" setup>
import type { DrawerRootEmits, DrawerRootProps } from 'vaul-vue'
import { useForwardPropsEmits } from 'reka-ui'
import { DrawerRoot } from 'vaul-vue'

const props = withDefaults(defineProps<DrawerRootProps>(), {
  shouldScaleBackground: true,
})

const emits = defineEmits<DrawerRootEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <DrawerRoot
    data-slot="drawer"
    v-bind="forwarded"
  >
    <slot />
  </DrawerRoot>
</template>

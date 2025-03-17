<script setup lang="ts">
import { cn } from '@/lib/utils'
import {
  MenubarRoot,
  type MenubarRootEmits,
  type MenubarRootProps,
  useForwardPropsEmits,
} from 'radix-vue'
import { computed, type HTMLAttributes } from 'vue'

const props = defineProps<MenubarRootProps & { class?: HTMLAttributes['class'] }>()
const emits = defineEmits<MenubarRootEmits>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <MenubarRoot
    v-bind="forwarded"
    :class="
      cn(
        'flex h-10 items-center gap-x-1 rounded-md border border-neutral-200 bg-white p-1 dark:border-neutral-800 dark:bg-neutral-950',
        props.class,
      )
    "
  >
    <slot />
  </MenubarRoot>
</template>

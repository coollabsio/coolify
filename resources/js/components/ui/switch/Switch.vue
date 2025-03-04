<script setup lang="ts">
import type { SwitchRootEmits, SwitchRootProps } from 'radix-vue'
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import {
  SwitchRoot,

  SwitchThumb,
  useForwardPropsEmits,
} from 'radix-vue'
import { computed } from 'vue'

const props = defineProps<SwitchRootProps & { class?: HTMLAttributes['class'] }>()

const emits = defineEmits<SwitchRootEmits>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <SwitchRoot v-bind="forwarded" :class="cn(
    'relative peer inline-flex h-6 w-9 shrink-0 cursor-pointer items-center rounded-lg transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:ring-offset-2 focus-visible:ring-offset-white disabled:cursor-not-allowed disabled:opacity-50 before:absolute before:inset-y-2 before:inset-x-0.5  before:rounded-md before:transition-colors before:data-[state=checked]:bg-neutral-900 before:data-[state=unchecked]:bg-neutral-200 dark:focus-visible:ring-neutral-300 dark:focus-visible:ring-offset-neutral-950 before:dark:data-[state=checked]:bg-primary before:dark:data-[state=unchecked]:bg-neutral-800',
    props.class,
  )">
    <SwitchThumb
      :class="cn('pointer-events-none block size-5 rounded-full bg-white ring-0 transition-transform data-[state=checked]:translate-x-5 bg-white relative z-10')">
      <slot name="thumb" />
    </SwitchThumb>
  </SwitchRoot>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { cn } from '@/lib/utils'
const props = defineProps<{
  size?: number
}>()
</script>

<template>
  <Link href="/">
  <img src="/coolify-transparent.png" alt="Coolify"
    :class="cn('h-4 h-4 aspect-square', props.size ? `h-${props.size} w-auto aspect-square` : '')" />
  </Link>
</template>
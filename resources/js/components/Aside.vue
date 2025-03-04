<script setup lang="ts">
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area'
import { Button } from '@/components/ui/button'
import { Link } from '@inertiajs/vue3'
import { cn } from '@/lib/utils'

const props = defineProps<{
  sidebarNavItems: any[]
}>()
</script>
<template>
  <aside class="-mx-2 lg:w-1/5 w-full max-w-[calc(100vw-30px)] md:max-w-full">
    <ScrollArea>
      <nav class="flex space-x-2 lg:flex-col lg:space-x-0 lg:space-y-1">
        <Link prefetch v-for="item in sidebarNavItems" :key="item.title" :href="item.href">
        <Button variant="ghost" :class="cn(
          'w-fit xl:w-full text-left justify-start text-muted-foreground hover:bg-primary'
        )">
          <component v-if="item.icon" :is="item.icon" class="w-4 h-4 mr-2" />
          {{ item.title }}
        </Button>
        </Link>
      </nav>
      <ScrollBar orientation="horizontal" class="h-1.5" />
    </ScrollArea>
  </aside>
</template>
<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { PanelRightOpen, PanelRightClose } from 'lucide-vue-next'
import { useSidebar } from './utils'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
const { toggleSidebar, state } = useSidebar()
</script>

<template>
  <TooltipProvider :delay-duration="0">
    <Tooltip>
      <TooltipTrigger as-child>
        <Button data-sidebar="trigger" variant="ghost" size="icon"
          :class="cn('h-7 w-7 rounded-none text-muted-foreground', props.class)" @click="toggleSidebar">
          <div v-if="state === 'expanded'">
            <PanelRightOpen />
          </div>
          <div v-else>
            <PanelRightClose />
          </div>
          <span class="sr-only">Toggle Sidebar</span>
        </Button>
      </TooltipTrigger>
      <TooltipContent side="right">
        <p>Toggle Sidebar</p>
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
</template>

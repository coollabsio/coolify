<script setup lang="ts">
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area'
import { Button } from '@/components/ui/button'
import { Link, usePage } from '@inertiajs/vue3'
import { cn } from '@/lib/utils'
import { Badge } from '@/components/ui/badge'
import { PageProps } from '@/types/PagePropsType';
defineProps<{
    sidebarNavItems: any[]
}>()

const page = usePage<PageProps>()

const isActive = (item: any) => {
    return new URL(item.href).pathname === page.url
}
</script>
<template>
    <aside class="-mx-2 min-w-[200px] max-w-[calc(100vw-30px)] md:max-w-full ">
        <ScrollArea>
            <nav class="flex space-x-2 lg:flex-col lg:space-x-0 lg:space-y-1">
                <Link prefetch v-for="item in sidebarNavItems" :key="item.title" :href="item.href">
                <Button variant="ghost" :class="cn(
                    'w-fit xl:w-full text-left justify-start',
                    isActive(item) ? 'text-warning' : 'text-muted-foreground'
                )">
                    <component v-if="item.icon" :is="item.icon" class="w-4 h-4 mr-2" />
                    {{ item.title }}
                    <Badge v-if="item.indicator"
                        :variant="item.indicator.toLowerCase() === 'running' ? 'success' : 'destructive'">
                        {{ item.indicator }}
                    </Badge>
                </Button>
                </Link>
            </nav>
            <ScrollBar orientation="horizontal" class="h-1.5" />
        </ScrollArea>
    </aside>
</template>

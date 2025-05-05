<script setup>
import AppSidebar from '@/components/AppSidebar.vue'
import { SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar'
</script>

<template>
  <SidebarProvider>
    <AppSidebar />
    <main class="p-2 w-full">
      <SidebarTrigger />
      <RouterView />
    </main>
  </SidebarProvider>
</template>
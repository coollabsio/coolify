<script setup lang="ts">
import { Button } from '@/components/ui/button'
import Sidebar from '@/components/Sidebar.vue'
defineProps({ user: Object })
</script>

<template>
    <Sidebar />
    <p>Hello {{ user }}, welcome to your first Inertia app!</p>
    <Button>Click me</Button>
</template>

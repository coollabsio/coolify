<script setup lang="ts">
import MainView from '@/components/MainView.vue'
defineProps({ user: Object })
</script>

<template>
    <MainView>
        <template #title>
            Projects
        </template>
        <template #subtitle>Manage your projects.</template>
    </MainView>
</template>

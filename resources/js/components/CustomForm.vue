<script setup lang="ts">
import { Button } from './ui/button';
import { Loader2 } from 'lucide-vue-next';
defineProps<{
  onSubmit: (e: Event) => void;
  isSubmitting: boolean;
  isFormValid: boolean;
  isFormDirty: boolean;
}>();
</script>

<template>
  <form class="space-y-4" @submit="onSubmit">
    <slot />
    <Button v-if="isSubmitting" type="submit" disabled="true">Saving...
      <Loader2 class="w-4 h-4 ml-2 animate-spin" />
    </Button>
    <Button v-else type="submit" :disabled="!isFormValid || !isFormDirty">Save</Button>
  </form>
</template>
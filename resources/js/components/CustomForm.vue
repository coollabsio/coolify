<script setup lang="ts">
import { Button } from './ui/button';
import { Loader2 } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
  onSubmit: (e: Event) => void;
  isSubmitting: boolean;
  isFormValid: boolean;
  isFormDirty: boolean;
  confirmationMessage?: string;
  customSavingText?: string;
}>();
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'

const isOpen = ref(false);
const emit = defineEmits(['update:open']);

const onSubmit = (e: Event) => {
  e.preventDefault();
  isOpen.value = false;
  props.onSubmit(e);
}
</script>

<template>
  <div v-if="confirmationMessage" class="space-y-4">
    <slot />
    <Popover v-model:open="isOpen">
      <PopoverTrigger :disabled="!isFormValid || !isFormDirty" class="w-full md:w-fit">
        <div class="w-full">
          <Button v-if="isSubmitting" class="md:w-fit w-full" type="submit" disabled="true">{{ customSavingText ||
            'Saving...' }}
            <Loader2 class="w-4 h-4 ml-2 animate-spin" />
          </Button>
          <Button v-else class="md:w-fit w-full" type="submit" :disabled="!isFormValid || !isFormDirty">Save</Button>
        </div>
      </PopoverTrigger>
      <PopoverContent align="start" class="w-full">
        <div class="grid gap-4">
          <div class="text-sm" v-html="confirmationMessage || 'Are you sure you want to continue?'"></div>
          <div class="flex gap-2 justify-between">
            <Button class="md:w-fit w-full" type="button" variant="outline" @click="isOpen = false">No</Button>
            <Button class="md:w-fit w-full" type="submit" variant="destructive" :disabled="!isFormValid || !isFormDirty"
              @click="onSubmit">Yes</Button>
          </div>
        </div>
      </PopoverContent>
    </Popover>
  </div>
  <form v-else class="space-y-4" @submit="onSubmit">
    <slot />
    <Button v-if="isSubmitting" class="md:w-fit w-full" type="submit" disabled="true">{{ customSavingText ||
      'Saving...' }}
      <Loader2 class="w-4 h-4 ml-2 animate-spin" />
    </Button>
    <Button v-else class="md:w-fit w-full" type="submit" :disabled="!isFormValid || !isFormDirty">Save</Button>
  </form>
</template>
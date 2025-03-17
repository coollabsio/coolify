<script setup lang="ts">
import { Button } from './ui/button';
import { Loader2 } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import Confirmation from './Confirmation.vue';

const props = defineProps<{
    onSubmit: (e: Event) => void;
    isSubmitting: boolean;
    isFormValid: boolean;
    isFormDirty: boolean;
    confirmationMessage?: string;
    customSavingText?: string;
    cancelText?: string;
    continueText?: string;
}>();

const isLoading = ref(props.isSubmitting);

// Watch for changes in isSubmitting to update isLoading
watch(() => props.isSubmitting, (newValue) => {
    isLoading.value = newValue;
});

const handleConfirm = () => {
    const event = new Event('submit');
    try {
        props.onSubmit(event);
    } catch (error) {
        isLoading.value = false;
    }
};
</script>

<template>
    <div v-if="confirmationMessage" class="space-y-4">
        <slot />
        <Confirmation v-model:isLoading="isLoading" :disabled="!isFormValid || !isFormDirty || isSubmitting"
            buttonText="Save" :loadingText="customSavingText || 'Saving...'" :confirmationMessage="confirmationMessage"
            :cancelText="cancelText" :continueText="continueText" @continue="handleConfirm" />
    </div>
    <form v-else class="space-y-4" @submit="props.onSubmit">
        <slot />
        <Button v-if="isSubmitting" class="md:w-fit w-full" type="submit" disabled="true">
            {{ customSavingText || 'Saving...' }}
            <Loader2 class="w-4 h-4 ml-2 animate-spin" />
        </Button>
        <Button v-else class="md:w-fit w-full" type="submit"
            :disabled="!isFormValid || !isFormDirty || isSubmitting">Save</Button>
    </form>
</template>

<script setup lang="ts">
import { Button } from './ui/button';
import { Loader2 } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

const props = defineProps<{
    buttonText?: string;
    loadingText?: string;
    confirmationMessage?: string;
    cancelText?: string;
    continueText?: string;
    variant?: 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'link';
    disabled?: boolean;
    buttonClass?: string;
    size?: 'default' | 'sm' | 'lg';
    isLoading?: boolean;
}>();

const emit = defineEmits(['continue', 'update:isLoading']);

const isOpen = ref(false);
const isLoading = ref(props.isLoading || false);

// Watch for changes in the isLoading prop
watch(() => props.isLoading, (newValue) => {
    if (newValue !== undefined) {
        isLoading.value = newValue;
    }
});

const handleContinue = () => {
    isOpen.value = false;
    isLoading.value = true;
    emit('update:isLoading', true);
    emit('continue');
};

const handleCancel = () => {
    isOpen.value = false;
};
</script>

<template>
    <Popover v-model:open="isOpen">
        <PopoverTrigger :disabled="disabled || isLoading" :class="buttonClass || 'w-full md:w-fit'">
            <Button :size="size" :variant="isLoading ? 'secondary' : variant || 'default'"
                :disabled="disabled || isLoading">
                <template v-if="isLoading">
                    <Loader2 class="w-4 h-4 mr-2 animate-spin" />
                    {{ loadingText || 'Loading...' }}
                </template>
                <template v-else>
                    <slot>{{ buttonText || 'Continue' }}</slot>
                </template>
            </Button>
        </PopoverTrigger>
        <PopoverContent align="start" class="w-auto min-w-[200px]" :sideOffset="5">
            <div class="grid gap-4">
                <div class="text-sm" v-html="confirmationMessage || 'Are you sure you want to continue?'" />
                <div class="flex gap-2 justify-between">
                    <Button type="button" variant="secondary" @click="handleCancel">
                        {{ cancelText || 'Cancel' }}
                    </Button>
                    <Button type="button" variant="destructive" @click="handleContinue">
                        {{ continueText || 'Continue' }}
                    </Button>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>

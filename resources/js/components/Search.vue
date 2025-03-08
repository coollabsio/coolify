<script setup lang="ts">
import { Input } from '@/components/ui/input'
import { ref, nextTick, onMounted, watch, computed } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { Search as SearchIcon } from 'lucide-vue-next'

const isMac = computed(() => window.navigator.platform.includes('Mac'))
const search = ref('')
const isSearchVisible = ref(false)
const searchInputRef = ref<HTMLElement | null>(null)
const emit = defineEmits(['search'])

const debouncedSearch = useDebounceFn((value: string | number) => {
    emit('search', String(value))
}, 100)

watch(isSearchVisible, async (newValue) => {
    if (newValue) {
        await nextTick()
        const input = searchInputRef.value?.querySelector('input')
        if (input) {
            input.focus()
        }
    }
})

const toggleSearch = () => {
    isSearchVisible.value = !isSearchVisible.value
    if (!isSearchVisible.value) {
        search.value = ''
        emit('search', '')
    }
}

const handleBlur = () => {
    if (!search.value) {
        toggleSearch()
    }
}

onMounted(() => {
    window.addEventListener('keydown', (e) => {
        // Check for Command+F (Mac) or Control+F (Windows/Linux)
        if ((e.metaKey || e.ctrlKey) && e.key === 'f') {
            e.preventDefault(); // Prevent the default browser find behavior
            isSearchVisible.value = true;
            nextTick(() => {
                const input = searchInputRef.value?.querySelector('input');
                if (input) {
                    input.focus();
                }
            });
        }
    });
});

</script>

<template>
    <div class="flex items-center">
        <div class="relative flex items-center">
            <div class="px-2 flex items-center gap-2">
                <SearchIcon @click="toggleSearch"
                    class="size-4 cursor-pointer text-muted-foreground hover:text-foreground transition-colors"
                    :class="{ 'text-primary': isSearchVisible }" />
                <span class="text-xs text-muted-foreground">{{ isMac ? '⌘' : 'Ctrl' }}F</span>
            </div>
            <div v-show="isSearchVisible" class="absolute right-0 top-1/2 -translate-y-1/2 transform"
                ref="searchInputRef">
                <Input size="xs" class="w-48 lg:w-96 pl-8 transition-all duration-200" v-model="search"
                    placeholder="Search" @update:model-value="debouncedSearch" @blur="handleBlur"
                    @keydown.escape="toggleSearch" />
                <SearchIcon class="absolute left-2 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            </div>
        </div>
    </div>
</template>

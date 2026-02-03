<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Edit | Coolify
        </x-slot>
        <form wire:submit='submit' class="flex flex-col pb-10">
            <div class="flex gap-2">
                <h1>{{ data_get_str($project, 'name')->limit(15) }}</h1>
                <div class="flex items-end gap-2">
                    <x-forms.button type="submit">Save</x-forms.button>
                    <livewire:project.delete-project :disabled="!$project->isEmpty()" :project_id="$project->id" />
                </div>
            </div>
            <div class="pt-2 pb-10">Edit project details here.</div>
            <div class="flex gap-2">
                <x-forms.input label="Name" id="name" />
                <x-forms.input label="Description" id="description" />
                <div class="flex flex-col gap-1">
                    <label class="flex items-center gap-1 text-sm font-medium">
                        Color
                        <x-helper helper="Choose a color to visually distinguish this project" />
                    </label>
                    <div class="flex items-center gap-2" x-data="{ showPicker: false }">
                        <input
                            type="color"
                            wire:model="color"
                            id="colorPicker"
                            class="sr-only"
                            x-show="showPicker"
                            @change="showPicker = false">
                        <button
                            type="button"
                            @click="showPicker = true; $nextTick(() => document.getElementById('colorPicker').click())"
                            class="flex items-center justify-center w-20 h-8 text-xs font-medium border rounded cursor-pointer border-coolgray-300 dark:border-coolgray-500 hover:border-coolgray-400 dark:hover:border-coolgray-400"
                            :style="$wire.color ? 'background-color: ' + $wire.color : ''">
                            <span :class="$wire.color ? 'text-white mix-blend-difference' : 'dark:text-white'">
                                Select
                            </span>
                        </button>
                        @if($color)
                            <button
                                type="button"
                                wire:click="$set('color', null)"
                                class="flex items-center justify-center size-8 text-white rounded hover:bg-coolgray-400 dark:hover:bg-coolgray-300"
                                title="Clear color">
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </form>
</div>
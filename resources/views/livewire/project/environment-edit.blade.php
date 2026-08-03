<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Edit | Coolify
    </x-slot>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-end gap-2">
            <h1>Environment: {{ data_get_str($environment, 'name')->limit(15) }}</h1>
            <x-forms.button canGate="update" :canResource="$environment" type="submit">Save</x-forms.button>
            @can('delete', $environment)
                <livewire:project.delete-environment :disabled="!$environment->isEmpty()" :environment_id="$environment->id" />
            @endcan
        </div>
        <nav class="flex pt-2 pb-10">
            <ol class="flex flex-wrap items-center gap-y-1">
                <li class="inline-flex items-center">
                    <div class="flex items-center">
                        <a class="text-xs truncate lg:text-sm" {{ wireNavigate() }}
                            href="{{ route('project.show', ['project_uuid' => $project->uuid]) }}">
                            {{ $project->name }}</a>
                    </div>
                </li>
                <li>
                    <div class="flex items-center">
                        <a class="text-xs truncate lg:text-sm" {{ wireNavigate() }}
                            href="{{ route('project.resource.index', ['environment_uuid' => $environment->uuid, 'project_uuid' => $project->uuid]) }}">
                            {{ $environment->name }}
                        </a>
                    </div>
                </li>
                <li>
                    <div class="flex items-center">
                        <svg aria-hidden="true" class="w-3 h-3 mx-1 font-bold dark:text-warning" fill="currentColor"
                            viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd"
                                d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                clip-rule="evenodd"></path>
                        </svg>
                        Edit
                    </div>
                </li>
            </ol>
        </nav>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="name" />
            <x-forms.input label="Description" id="description" />
            <div class="flex flex-col gap-1">
                <label class="flex items-center gap-1 text-sm font-medium">
                    Color
                    <x-helper helper="Choose a color to visually distinguish this environment" />
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
                        <span :class="$wire.color ? getContrastTextColor($wire.color) : 'dark:text-white'">
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

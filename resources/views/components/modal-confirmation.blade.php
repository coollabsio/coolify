@props([
'title' => 'Are you sure?',
'isErrorButton' => false,
'buttonTitle' => 'Confirm Action',
'buttonFullWidth' => false,
'customButton' => null,
'disabled' => false,
'action' => 'delete',
'content' => null,
'checkboxes' => [],
'checkboxActions' => [],
'actions' => [],
'confirmWithText' => true,
'confirmWithPassword' => true,
])

<div x-data="{
    modalOpen: false,
    step: {{ !empty($checkboxes) ? 1 : ($confirmWithText ? 2 : 3) }},
    selectedActions: @js(collect($checkboxes)->where('model', true)->pluck('id')->toArray()),
    deleteText: '',
    password: '',
    checkboxActions: @js($checkboxActions),
    actions: @js($actions),
    getActionText(action) {
        return this.checkboxActions[action] || action;
    }
}" @keydown.escape.window="modalOpen = false" :class="{ 'z-40': modalOpen }" class="relative w-auto h-auto">
    @if ($customButton)
    @if ($buttonFullWidth)
    <x-forms.button @click="modalOpen=true" class="w-full">
        {{ $customButton }}
    </x-forms.button>
    @else
    <x-forms.button @click="modalOpen=true">
        {{ $customButton }}
    </x-forms.button>
    @endif
    @else
    @if ($content)
    <div @click="modalOpen=true">
        {{ $content }}
    </div>
    @else
    @if ($disabled)
    @if ($buttonFullWidth)
    <x-forms.button class="w-full" isError disabled wire:target>
        {{ $buttonTitle }}
    </x-forms.button>
    @else
    <x-forms.button isError disabled wire:target>
        {{ $buttonTitle }}
    </x-forms.button>
    @endif
    @elseif ($isErrorButton)
    @if ($buttonFullWidth)
    <x-forms.button class="w-full" isError @click="modalOpen=true">
        {{ $buttonTitle }}
    </x-forms.button>
    @else
    <x-forms.button isError @click="modalOpen=true">
        {{ $buttonTitle }}
    </x-forms.button>
    @endif
    @else
    @if ($buttonFullWidth)
    <x-forms.button @click="modalOpen=true" class="flex w-full gap-2" wire:target>
        {{ $buttonTitle }}
    </x-forms.button>
    @else
    <x-forms.button @click="modalOpen=true" class="flex gap-2" wire:target>
        {{ $buttonTitle }}
    </x-forms.button>
    @endif
    @endif
    @endif
    @endif
    <template x-teleport="body">
        <div x-show="modalOpen" class="fixed top-0 lg:pt-10 left-0 z-[99] flex items-start justify-center w-screen h-screen" x-cloak>
            <div x-show="modalOpen" x-transition:enter="ease-out duration-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="modalOpen=false" class="absolute inset-0 w-full h-full bg-black bg-opacity-20 backdrop-blur-sm"></div>
            <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100" x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95" class="relative w-full py-6 border rounded min-w-full lg:min-w-[36rem] max-w-fit bg-neutral-100 border-neutral-400 dark:bg-base px-7 dark:border-coolgray-300">
                <div class="flex items-center justify-between pb-3">
                    <h3 class="text-2xl font-bold">{{ $title }}</h3>
                    <button @click="modalOpen=false"
                        class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-coolgray-300">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="relative w-auto pb-8">
                    @if(!empty($checkboxes))
                    <!-- Step 1: Select actions -->
                    <div x-show="step === 1">
                        <div class="flex justify-between items-center mb-4">
                            <div class="px-2">Select the actions you want to perform:</div>
                        </div>
                        @foreach($checkboxes as $index => $checkbox)
                        <x-forms.checkbox 
                            :id="$checkbox['id']" 
                            :wire:model="$checkbox['model']" 
                            :label="$checkbox['label']" 
                            x-on:change="$event.target.checked ? (selectedActions.includes('{{ $checkbox['id'] }}') || selectedActions.push('{{ $checkbox['id'] }}')) : selectedActions = selectedActions.filter(a => a !== '{{ $checkbox['id'] }}')"
                            :checked="$checkbox['model']"
                        ></x-forms.checkbox>
                        @endforeach
                    </div>
                    @else
                    <div x-init="step = {{ $confirmWithText ? 2 : 3 }}"></div>
                    @endif

                    <!-- Step 2: Confirm deletion -->
                    @if($confirmWithText)
                    <div x-show="step === 2">
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                            <p class="font-bold">Warning</p>
                            <p>This operation is not reversible. Please proceed with caution.</p>
                        </div>
                        <div class="px-2 mb-4">The following actions will be performed:</div>
                        <ul class="mb-4 space-y-2">
                            <template x-for="action in actions" :key="action">
                                <li class="flex items-center text-red-500">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                    <span x-text="action" class="font-bold"></span>
                                </li>
                            </template>
                            <template x-for="action in selectedActions" :key="action">
                                <li class="flex items-center text-red-500">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                    <span x-text="checkboxActions[action]" class="font-bold"></span>
                                </li>
                            </template>
                        </ul>
                        <div class="text-black dark:text-white mb-4">Please type <span class="text-red-500 font-bold">DELETE</span> to confirm this destructive action:</div>
                        <input type="text" x-model="deleteText" class="w-full p-2 rounded mb-6 text-black input">
                    </div>
                    @endif

                    <!-- Step 3: Password confirmation -->
                    @if($confirmWithPassword)
                    <div x-show="step === 3">
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                            <p class="font-bold">Final Confirmation</p>
                            <p>Please enter your password to confirm this destructive action.</p>
                        </div>
                        <div class="mb-4">
                            <label for="password-confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Your Password
                            </label>
                            <input type="password" id="password-confirm" x-model="password" class="input" placeholder="Enter your password">
                        </div>
                    </div>
                    @endif
                </div>
                <!-- Navigation buttons -->
                <div class="flex flex-row justify-between mt-4">
                    <x-forms.button 
                        @click="step > 1 ? step-- : modalOpen = false" 
                        x-text="(step === 1 && {{ json_encode(empty($checkboxes)) }}) || step === 1 ? 'Cancel' : 'Back'" 
                        class="w-24 dark:bg-coolgray-200 dark:hover:bg-coolgray-300"
                    ></x-forms.button>
                    
                    <template x-if="step === 1">
                        <x-forms.button 
                            @click="step++" 
                            x-bind:disabled="selectedActions.length === 0"
                            class="w-auto" 
                            isError
                        >
                            Continue Permanent Deletion
                        </x-forms.button>
                    </template>
                    
                    <template x-if="step === 2">
                        <x-forms.button 
                            @click="step++" 
                            x-bind:disabled="deleteText !== 'DELETE'"
                            class="w-auto" 
                            isError
                        >
                            Delete Permanently
                        </x-forms.button>
                    </template>
                    
                    <template x-if="step === 3">
                        <x-forms.button 
                            @click="$wire.{{ $action }}(selectedActions, password); modalOpen = false" 
                            x-bind:disabled="!password"
                            class="w-auto" 
                            isError
                        >
                            Confirm Permanent Deletion
                        </x-forms.button>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>

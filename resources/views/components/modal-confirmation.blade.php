@props([
    'title' => 'Are you sure?',
    'isErrorButton' => false,
    'buttonTitle' => 'Confirm Action',
    'buttonFullWidth' => false,
    'customButton' => null,
    'disabled' => false,
    'submitAction' => 'delete',
    'content' => null,
    'checkboxes' => [],
    'actions' => [],
    'confirmWithText' => true,
    'confirmationText' => 'Confirm Deletion',
    'confirmationLabel' => 'Please confirm the execution of the actions by entering the Name below',
    'shortConfirmationLabel' => 'Name',
    'confirmWithPassword' => true,
    'step1ButtonText' => 'Continue',
    'step2ButtonText' => 'Continue',
    'step3ButtonText' => 'Confirm',
    'dispatchEvent' => false,
    'dispatchEventType' => 'success',
    'dispatchEventMessage' => '',
])

<div x-data="{
    modalOpen: false,
    step: {{ empty($checkboxes) ? 2 : 1 }},
    initialStep: {{ empty($checkboxes) ? 2 : 1 }},
    finalStep: {{ $confirmWithPassword ? 3 : 2 }},
    deleteText: '',
    password: '',
    actions: @js($actions),
    confirmationText: @js($confirmationText),
    userConfirmationText: '',
    confirmWithText: @js($confirmWithText),
    confirmWithPassword: @js($confirmWithPassword),
    copied: false,
    submitAction: @js($submitAction),
    passwordError: '',
    selectedActions: @js(collect($checkboxes)->pluck('id')->filter(fn($id) => $this->$id)->values()->all()),
    dispatchEvent: @js($dispatchEvent),
    dispatchEventType: @js($dispatchEventType),
    dispatchEventMessage: @js($dispatchEventMessage),
    resetModal() {
        this.step = this.initialStep;
        this.deleteText = '';
        this.password = '';
        this.userConfirmationText = '';
        this.selectedActions = @js(collect($checkboxes)->pluck('id')->filter(fn($id) => $this->$id)->values()->all());
        $wire.$refresh();
    },
    step1ButtonText: @js($step1ButtonText),
    step2ButtonText: @js($step2ButtonText),
    step3ButtonText: @js($step3ButtonText),
    validatePassword() {
        if (this.confirmWithPassword && !this.password) {
            return 'Password is required.';
        }
        return '';
    },
    submitForm() {
        if (this.confirmWithPassword) {
            this.passwordError = this.validatePassword();
            if (this.passwordError) {
                return Promise.resolve(this.passwordError);
            }
        }

        const methodName = this.submitAction.split('(')[0];
        const paramsMatch = this.submitAction.match(/\((.*?)\)/);
        const params = paramsMatch ? paramsMatch[1].split(',').map(param => param.trim()) : [];

        if (this.confirmWithPassword) {
            params.push(this.password);
        }
        params.push(this.selectedActions);

        return $wire[methodName](...params)
            .then(result => {
                if (result === true) {
                    return true;
                } else if (typeof result === 'string') {
                    return result;
                }
            });
    },
    copyConfirmationText() {
        navigator.clipboard.writeText(this.confirmationText);
        this.copied = true;
        setTimeout(() => {
            this.copied = false;
        }, 2000);
    },
    toggleAction(id) {
        const index = this.selectedActions.indexOf(id);
        if (index > -1) {
            this.selectedActions.splice(index, 1);
        } else {
            this.selectedActions.push(id);
        }
    }
}" @keydown.escape.window="modalOpen = false; resetModal()" :class="{ 'z-40': modalOpen }"
    class="relative w-auto h-auto">
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
                    <x-forms.button @click="modalOpen=true" class="flex gap-2 w-full" wire:target>
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
        <div x-show="modalOpen"
            class="fixed top-0 lg:pt-10 left-0 z-[99] flex items-start justify-center w-screen h-screen" x-cloak>
            <div x-show="modalOpen"
                class="absolute inset-0 w-full h-full bg-black bg-opacity-20 backdrop-blur-sm"></div>
            <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                class="relative w-full py-6 border rounded min-w-full lg:min-w-[36rem] max-w-[48rem] bg-neutral-100 border-neutral-400 dark:bg-base px-7 dark:border-coolgray-300">
                <div class="flex justify-between items-center pb-3">
                    <h3 class="pr-8 text-2xl font-bold">{{ $title }}</h3>
                    <button @click="modalOpen = false; resetModal()"
                        class="flex absolute top-2 right-2 justify-center items-center w-8 h-8 rounded-full dark:text-white hover:bg-coolgray-300">
                        <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="relative pb-8 w-auto">
                    @if (!empty($checkboxes))
                        <!-- Step 1: Select actions -->
                        <div x-show="step === 1">
                            <div class="flex justify-between items-center">
                                <h4>Actions</h4>
                            </div>
                            @foreach ($checkboxes as $index => $checkbox)
                                <div class="flex justify-between items-center mb-2">
                                    <x-forms.checkbox fullWidth :label="$checkbox['label']" :id="$checkbox['id']"
                                        :wire:model="$checkbox['id']"
                                        x-on:change="toggleAction('{{ $checkbox['id'] }}')" :checked="$this->{$checkbox['id']}"
                                        x-bind:checked="selectedActions.includes('{{ $checkbox['id'] }}')" />
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <!-- Step 2: Confirm deletion -->
                    <div x-show="step === 2">
                        <div class="p-4 mb-4 text-white border-l-4 border-red-500 bg-error" role="alert">
                            <p class="font-bold">Warning</p>
                            <p>This operation is permanent and cannot be undone. Please think again before proceeding!
                            </p>
                        </div>
                        <div class="mb-4">The following actions will be performed:</div>
                        <ul class="mb-4 space-y-2">
                            @foreach ($actions as $action)
                                <li class="flex items-center text-red-500">
                                    <svg class="flex-shrink-0 mr-2 w-5 h-5" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                    <span>{{ $action }}</span>
                                </li>
                            @endforeach
                            @foreach ($checkboxes as $checkbox)
                                <template x-if="selectedActions.includes('{{ $checkbox['id'] }}')">
                                    <li class="flex items-center text-red-500">
                                        <svg class="flex-shrink-0 mr-2 w-5 h-5" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                        <span>{{ $checkbox['label'] }}</span>
                                    </li>
                                </template>
                            @endforeach
                        </ul>
                        @if ($confirmWithText)
                            <div class="mb-4">
                                <h4 class="mb-2 text-lg font-semibold">Confirm Actions</h4>
                                <p class="mb-2 text-sm">{{ $confirmationLabel }}</p>
                                <div class="relative mb-2">
                                    <input type="text" x-model="confirmationText"
                                        class="p-2 pr-10 w-full text-black rounded cursor-text input" readonly>
                                    <button @click="copyConfirmationText()"
                                        class="absolute right-2 top-1/2 text-gray-500 transform -translate-y-1/2 hover:text-gray-700"
                                        title="Copy confirmation text" x-ref="copyButton">
                                        <template x-if="!copied">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20"
                                                fill="currentColor">
                                                <path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z" />
                                                <path
                                                    d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z" />
                                            </svg>
                                        </template>
                                        <template x-if="copied">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-green-500"
                                                viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd"
                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </template>
                                    </button>
                                </div>

                                <label for="userConfirmationText"
                                    class="block mt-4 text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ $shortConfirmationLabel }}
                                </label>
                                <input type="text" x-model="userConfirmationText"
                                    class="p-2 mt-1 w-full text-black rounded input">
                            </div>
                        @endif
                    </div>

                    <!-- Step 3: Password confirmation -->
                    <div x-show="step === 3 && confirmWithPassword">
                        <div class="p-4 mb-4 text-white border-l-4 border-red-500 bg-error" role="alert">
                            <p class="font-bold">Final Confirmation</p>
                            <p>Please enter your password to confirm this destructive action.</p>
                        </div>
                        <div class="flex flex-col gap-2 mb-4">
                            <label for="password-confirm"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Your Password
                            </label>
                            <form @submit.prevent @keydown.enter.prevent>
                                <input type="password" id="password-confirm" x-model="password" class="w-full input"
                                    placeholder="Enter your password">
                            </form>
                            <p x-show="passwordError" x-text="passwordError" class="mt-1 text-sm text-red-500"></p>
                            @error('password')
                                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
                <!-- Navigation buttons -->
                <div class="flex flex-wrap gap-2 justify-between mt-4">
                    <template x-if="step > initialStep">
                        <x-forms.button @click="step--" class="w-24 dark:bg-coolgray-200 dark:hover:bg-coolgray-300">
                            Back
                        </x-forms.button>
                    </template>
                    <template x-if="step === initialStep">
                        <x-forms.button @click="modalOpen = false; resetModal()"
                            class="w-24 dark:bg-coolgray-200 dark:hover:bg-coolgray-300">
                            Cancel
                        </x-forms.button>
                    </template>

                    <template x-if="step === 1">
                        <x-forms.button @click="step++" class="w-auto" isError>
                            <span x-text="step1ButtonText"></span>
                        </x-forms.button>
                    </template>

                    <template x-if="step === 2">
                        <x-forms.button x-bind:disabled="confirmWithText && userConfirmationText !== confirmationText"
                            class="w-auto" isError
                            @click="
                            if (dispatchEvent) {
                                $wire.dispatch(dispatchEventType, dispatchEventMessage);
                            }
                            if (confirmWithPassword) {
                                step++;
                            } else {
                                modalOpen = false;
                                resetModal();
                                submitForm();
                            }">
                            <span x-text="step2ButtonText"></span>
                        </x-forms.button>
                    </template>

                    <template x-if="step === 3 && confirmWithPassword">
                        <x-forms.button x-bind:disabled="!password" class="w-auto" isError
                            @click="
                            if (dispatchEvent) {
                                $wire.dispatch(dispatchEventType, dispatchEventMessage);
                            }
                            submitForm().then((result) => {
                                if (result === true) {
                                    modalOpen = false;
                                    resetModal();
                                } else {
                                    passwordError = result;
                                }
                            });
                        ">
                            <span x-text="step3ButtonText"></span>
                        </x-forms.button>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>

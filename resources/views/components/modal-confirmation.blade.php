@props([
    'title' => 'Are you sure?',
    'isErrorButton' => false,
    'isHighlightedButton' => false,
    'buttonTitle' => 'Confirm Action',
    'buttonFullWidth' => false,
    'customButton' => null,
    'disabled' => false,
    'dispatchAction' => false,
    'submitAction' => 'delete',
    'content' => null,
    'checkboxes' => [],
    'actions' => [],
    'warningMessage' => null,
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
    'ignoreWire' => true,
    'temporaryDisableTwoStepConfirmation' => false,
])

@php
    use App\Models\InstanceSettings;
    $disableTwoStepConfirmation = data_get(InstanceSettings::find(0), 'disable_two_step_confirmation', false);
    $skipPasswordConfirmation = shouldSkipPasswordConfirmation();
    if ($temporaryDisableTwoStepConfirmation) {
        $disableTwoStepConfirmation = false;
    }
    $effectiveStep2ButtonText = ($skipPasswordConfirmation && $step2ButtonText === 'Continue') ? 'Confirm' : $step2ButtonText;
@endphp

<div {{ $ignoreWire ? 'wire:ignore' : '' }} x-data="{
    modalOpen: false,
    step: {{ empty($checkboxes) ? 2 : 1 }},
    initialStep: {{ empty($checkboxes) ? 2 : 1 }},
    finalStep: {{ $confirmWithPassword && !$skipPasswordConfirmation ? 3 : 2 }},
    deleteText: '',
    password: '',
    actions: @js($actions),
    confirmationText: (() => {
        const textarea = document.createElement('textarea');
        textarea.innerHTML = @js($confirmationText);
        return textarea.value;
    })(),
    userConfirmationText: '',
    confirmWithText: @js($confirmWithText && !$disableTwoStepConfirmation),
    confirmWithPassword: @js($confirmWithPassword && !$skipPasswordConfirmation),
    submitAction: @js($submitAction),
    dispatchAction: @js($dispatchAction),
    submitting: false,
    passwordError: '',
    selectedActions: @js(collect($checkboxes)->pluck('id')->filter(fn($id) => $this->$id)->values()->all()),
    dispatchEvent: @js($dispatchEvent),
    dispatchEventType: @js($dispatchEventType),
    dispatchEventMessage: @js($dispatchEventMessage),
    disableTwoStepConfirmation: @js($disableTwoStepConfirmation),
    skipPasswordConfirmation: @js($skipPasswordConfirmation),
    resetModal() {
        this.step = this.initialStep;
        this.deleteText = '';
        this.password = '';
        this.submitting = false;
        this.userConfirmationText = '';
        this.selectedActions = @js(collect($checkboxes)->pluck('id')->filter(fn($id) => $this->$id)->values()->all());
        $wire.$refresh();
    },
    step1ButtonText: @js($step1ButtonText),
    step2ButtonText: @js($effectiveStep2ButtonText),
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
        if (this.dispatchAction) {
            $wire.dispatch(this.submitAction);
            return Promise.resolve(true);
        }
        const methodName = this.submitAction.split('(')[0];
        const paramsMatch = this.submitAction.match(/\((.*?)\)/);
        const params = paramsMatch ? paramsMatch[1].split(',').map(param => param.trim()) : [];
        params.push(this.confirmWithPassword ? this.password : '');
        if (this.selectedActions.length > 0) {
            params.push(this.selectedActions);
        }
        return $wire[methodName](...params)
            .then(result => {
                if (result === true) {
                    return true;
                } else if (typeof result === 'string') {
                    return result;
                }
            });
    },
    toggleAction(id) {
        const index = this.selectedActions.indexOf(id);
        if (index > -1) {
            this.selectedActions.splice(index, 1);
        } else {
            this.selectedActions.push(id);
        }
    }
}"
    @keydown.escape.window="if (modalOpen) { modalOpen = false; resetModal(); }" :class="{ 'z-40': modalOpen }"
    class="relative w-auto h-auto">
    @if (isset($trigger))
        <div @click="modalOpen=true">{{ $trigger }}</div>
    @endif
    <template x-teleport="body">
        <div x-show="modalOpen" class="fixed top-0 left-0 z-99 flex items-center justify-center w-screen h-screen p-0 sm:p-4" x-cloak>
            <div x-show="modalOpen" class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
            <div x-show="modalOpen" class="relative w-full border rounded-none sm:rounded-sm bg-neutral-100 border-neutral-400 dark:bg-base dark:border-coolgray-300 flex flex-col">
                <div class="flex justify-between items-center py-6 px-7 shrink-0">
                    <h3 class="pr-8 text-2xl font-bold">{{ $title }}</h3>
                </div>
                <div class="relative w-auto overflow-y-auto px-7 pb-6">
                    <div x-show="step === 2">
                        <x-callout type="danger" title="Warning" class="mb-4">
                            {!! $warningMessage ?: 'This operation is permanent and cannot be undone. Please think again before proceeding!' !!}
                        </x-callout>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

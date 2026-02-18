<div>
    <form wire:submit="submit"
        class="flex flex-col items-center gap-4 p-4 bg-white border lg:items-start dark:bg-base dark:border-coolgray-300 border-neutral-200">
        <div class="flex flex-col w-full gap-2 lg:flex-row">
            @if ($env->is_shown_once)
                <x-forms.input disabled id="env.key" label="Name" />
                <svg class="icon my-1" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                        stroke-width="2">
                        <path d="M5 13a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6z" />
                        <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0-2 0m-3-5V7a4 4 0 1 1 8 0v4" />
                    </g>
                </svg>
                <x-modal-confirmation title="Confirm Environment Variable Deletion?" isErrorButton
                    buttonTitle="Delete" submitAction="delete"
                    :actions="['The selected environment variable will be permanently deleted.']"
                    confirmationText="{{ $env->key }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Environment Variable Name below"
                    shortConfirmationLabel="Environment Variable Name" :confirmWithPassword="false"
                    step2ButtonText="Permanently Delete" />
            @else
                @if ($env->is_multiline)
                    <x-forms.input id="env.key" label="Name" />
                    <x-forms.textarea type="password" id="env.value" label="Value" />
                @else
                    <x-forms.input id="env.key" label="Name" />
                    <x-forms.input type="password" id="env.value" label="Value" />
                @endif
            @endif
        </div>
        @if (!$env->is_shown_once)
            <div class="flex flex-col w-full gap-3">
                <div class="flex flex-wrap w-full items-center gap-4">
                    <x-forms.checkbox instantSave id="env.is_multiline" label="Is Multiline?" />
                    @if (!$env->is_multiline)
                        <x-forms.checkbox instantSave id="env.is_literal"
                            helper="When enabled, dollar signs ($) in the value will be treated literally instead of as variable references."
                            label="Is Literal?" />
                    @endif
                </div>
                <div class="flex w-full justify-end gap-2">
                    <x-forms.button type="submit">Update</x-forms.button>
                    <x-modal-confirmation title="Confirm Environment Variable Deletion?" isErrorButton
                        buttonTitle="Delete" submitAction="delete"
                        :actions="['The selected environment variable will be permanently deleted.']"
                        confirmationText="{{ $env->key }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Environment Variable Name below"
                        shortConfirmationLabel="Environment Variable Name" :confirmWithPassword="false"
                        step2ButtonText="Permanently Delete" />
                </div>
            </div>
        @endif
    </form>
</div>

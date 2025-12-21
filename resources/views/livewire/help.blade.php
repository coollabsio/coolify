<div class="flex flex-col w-full gap-2">
    <div>{{ __('common.your_feedback_helps') }}</div>
    <form wire:submit="submit" class="flex flex-col gap-4 pt-4">
        <x-forms.input minlength="3" required id="subject" label="Subject" placeholder="{{ __('forms.placeholders.help_subject') }}"></x-forms.input>
        <x-forms.textarea minlength="10" maxlength="1000" required rows="10" id="description" label="Description"
            class="font-sans" spellcheck
            placeholder="{{ __('forms.placeholders.help_message') }}"></x-forms.textarea>
        <div></div>
        <x-forms.button class="w-full mt-4" type="submit">{{ __('common.send') }}</x-forms.button>
    </form>
</div>

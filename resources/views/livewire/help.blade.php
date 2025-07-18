<div class="flex flex-col w-full gap-2">
    <div>Your feedback helps us to improve Coolify. Thank you! 💜</div>
    <form wire:submit="submit" class="flex flex-col gap-4 pt-4">
        <x-forms.input minlength="3" required id="subject" label="Subject" placeholder="Help with..."></x-forms.input>
        <x-forms.textarea minlength="10" maxlength="1000" required rows="10" id="description" label="Description"
            class="font-sans" spellcheck
            placeholder="Having trouble with... Please provide as much information as possible."></x-forms.textarea>
        <div></div>
        <x-forms.button class="w-full mt-4" type="submit">Send</x-forms.button>
    </form>
</div>

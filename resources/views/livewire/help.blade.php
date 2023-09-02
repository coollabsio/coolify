<div class="flex flex-col gap-2 rounded modal-box">
    <h3>How can we help?</h3>
    <div>You can report bug on the current page, or send us general feedback.</div>
    <form wire:submit.prevent="submit" class="flex flex-col gap-4 pt-4">
        <x-forms.input id="subject" label="Subject" placeholder="Summary of your problem."></x-forms.input>
        <x-forms.textarea id="description" label="Message"
            placeholder="Please provide as much information as possible."></x-forms.textarea>
        <x-forms.button class="w-full mt-4" type="submit">Send Request</x-forms.button>
    </form>
</div>

<div class="flex flex-col w-full gap-2">
    <div>Your feedback helps us to improve Coolify. Thank you! 💜</div>
    <form wire:submit="submit" class="flex flex-col gap-4 pt-4">
        <x-forms.input id="subject" label="Subject" placeholder="Summary of your problem."></x-forms.input>
        <x-forms.textarea rows="10" id="description" label="Description" class="font-sans" spellcheck
            placeholder="Please provide as much information as possible."></x-forms.textarea>
        <div></div>
        <x-forms.button class="w-full mt-4" type="submit" @click="modalOpen=false">Send</x-forms.button>
    </form>
</div>

<form wire:submit.prevent="addDynamicConfiguration" class="flex flex-col w-full gap-4">
    <x-forms.input autofocus id="fileName" label="Filename" required />
    <x-forms.textarea allowTab useMonacoEditor id="value" label="Configuration" required rows="20" />
    <x-forms.button type="submit" @click="slideOverOpen=false">Save</x-forms.button>
</form>

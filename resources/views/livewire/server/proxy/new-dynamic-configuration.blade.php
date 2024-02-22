<form wire:submit.prevent="addDynamicConfiguration" class="flex flex-col gap-4">
    <x-forms.input id="fileName" label="Filename (.yaml or .yml)" required />
    <x-forms.textarea id="value" label="Configuration" required rows="20" />
    <x-forms.button type="submit" @click="slideOverOpen=false">Save</x-forms.button>
</form>

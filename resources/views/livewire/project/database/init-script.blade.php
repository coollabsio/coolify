<div>
    <form wire:submit.prevent="submit">
        <div class="flex gap-2 items-end">
            <x-forms.input id="filename" label="Filename"/>
            <x-forms.button type="submit">Save</x-forms.button>
            <x-forms.button isError wire:click.prevent="delete">Delete</x-forms.button>
        </div>
        <x-forms.textarea id="content" label="Content"/>
    </form>
</div>

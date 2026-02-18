<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit="submit">
    <x-forms.input placeholder="NODE_ENV" id="key" label="Name" required />
    @if ($is_multiline)
        <x-forms.textarea id="value" label="Value" />
    @else
        <x-forms.input placeholder="production" id="value" label="Value" />
    @endif

    <x-forms.checkbox id="is_literal"
        helper="When enabled, dollar signs ($) in the value will be treated literally instead of as variable references."
        label="Is Literal?" />
    <x-forms.checkbox id="is_multiline" label="Is Multiline?" />
    <x-forms.checkbox id="is_shown_once"
        helper="Once saved, the value will be hidden and cannot be viewed again. You can only delete and re-add."
        label="Lock Value (One-time view)" />
    <x-forms.button type="submit" @click="slideOverOpen=false">
        Save
    </x-forms.button>
</form>

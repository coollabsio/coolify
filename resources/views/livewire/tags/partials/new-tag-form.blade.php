<form wire:submit="createTag" class="flex w-full flex-col gap-4">
    <x-forms.input id="newTagName" label="Name" placeholder="production" required
        helper="Lowercase, without spaces. You can add resources to the tag later." />
    <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
        <x-forms.button type="submit" isHighlighted>Create tag</x-forms.button>
    </div>
</form>

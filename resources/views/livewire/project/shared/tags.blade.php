<div>
    <h2>Tags</h2>
    <div class="flex gap-2 pt-4">
        @forelse ($this->resource->tags as $tagId => $tag)
            <div class="px-2 py-1 text-center text-white select-none w-fit bg-coolgray-100 hover:bg-coolgray-200">
                {{ $tag->name }}
                <svg wire:click="deleteTag('{{ $tag->id }}')"
                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                    class="inline-block w-3 h-3 rounded cursor-pointer stroke-current hover:bg-red-500">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </div>
        @empty
            <div>No tags yet</div>
        @endforelse
    </div>
    <form wire:submit='submit' class="flex items-end gap-2 pt-4">
        <div class="w-64">
            <x-forms.input label="Create new or assign existing tags"
                helper="You add more at once with space seperated list: web api something<br><br>If the tag does not exists, it will be created."
                wire:model="new_tag" />
        </div>
        <x-forms.button type="submit">Add</x-forms.button>
    </form>
    @if ($tags->count() > 0)
        <h3 class="pt-4">Already defined tags</h3>
        <div>Click to quickly add one.</div>
        <div class="flex gap-2 pt-4">
            @foreach ($tags as $tag)
                <x-forms.button wire:click="addTag('{{ $tag->id }}','{{ $tag->name }}')">
                    {{ $tag->name }}</x-forms.button>
            @endforeach
        </div>
    @endif
</div>

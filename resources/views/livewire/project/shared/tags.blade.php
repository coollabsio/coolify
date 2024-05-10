<div>
    <h2>Tags</h2>
    <div class="flex flex-wrap gap-2 pt-4">
        @if (data_get($this->resource, 'tags'))
            @forelse (data_get($this->resource,'tags') as $tagId => $tag)
                <div
                    class="px-2 py-1 text-center rounded select-none dark:text-white w-fit bg-neutral-200 hover:bg-neutral-300 dark:bg-coolgray-100 dark:hover:bg-coolgray-200">
                    {{ $tag->name }}
                    <svg wire:click="deleteTag('{{ $tag->id }}')" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24"
                        class="inline-block w-3 h-3 rounded cursor-pointer stroke-current hover:bg-red-500">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </div>
            @empty
                <div class="py-1">No tags yet</div>
            @endforelse
        @endif
    </div>
    <form wire:submit='submit' class="flex items-end gap-2 pt-4">
        <div class="w-64">
            <x-forms.input label="Create new or assign existing tags"
                helper="You add more at once with space separated list: web api something<br><br>If the tag does not exists, it will be created."
                wire:model="new_tag" />
        </div>
        <x-forms.button type="submit">Add</x-forms.button>
    </form>
    @if (count($tags) > 0)
        <h3 class="pt-4">Exisiting Tags</h3>
        <div>Click to add quickly</div>
        <div class="flex flex-wrap gap-2 pt-4">
            @foreach ($tags as $tag)
                <x-forms.button wire:click="addTag('{{ $tag->id }}','{{ $tag->name }}')">
                    {{ $tag->name }}</x-forms.button>
            @endforeach
        </div>
    @endif
</div>

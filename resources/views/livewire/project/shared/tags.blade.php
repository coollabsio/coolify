<div class="flex flex-col gap-6">
    <x-application.settings-section id="tag-assignment-section" title="Tags"
        helper="Organize this resource with reusable team tags. Separate multiple tag names with spaces.">
        @can('update', $resource)
            <form wire:submit="submit"
                class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                <x-forms.input id="newTags" label="Tag names"
                    helper="Existing tags are assigned automatically. New names create team tags."
                    placeholder="production api customer-a" />
                <x-forms.button type="submit"
                    class="bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                    <x-reicon name="plus" class="size-3.5" />
                    Add tags
                </x-forms.button>
            </form>
        @else
            <x-callout type="danger" title="Insufficient permissions">
                You do not have permission to manage tags for this resource.
            </x-callout>
        @endcan
    </x-application.settings-section>

    <x-application.settings-section id="assigned-tags-section" title="Assigned tags"
        helper="Tags currently attached to this resource." flush>
        @forelse (data_get($this->resource, 'tags', []) as $tag)
            <div wire:key="assigned-tag-{{ $tag->id }}"
                class="flex min-h-12 items-center gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 dark:border-white/[0.07]">
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                    <x-reicon name="tags" class="size-4" />
                </div>
                <span class="min-w-0 flex-1 truncate text-sm font-medium text-black dark:text-fg">
                    {{ $tag->name }}
                </span>
                @can('update', $resource)
                    <x-forms.button wire:click="deleteTag('{{ $tag->id }}')"
                        class="h-7! text-neutral-500 dark:text-fg-dim">
                        Remove
                    </x-forms.button>
                @endcan
            </div>
        @empty
            <x-empty size="sm" title="No tags assigned"
                description="Add a tag above or select one from your team's available tags.">
                <x-slot:icon>
                    <x-reicon name="tags" class="size-8" />
                </x-slot:icon>
            </x-empty>
        @endforelse
    </x-application.settings-section>

    @can('update', $resource)
        @if (count($filteredTags) > 0)
            <x-application.settings-section id="available-tags-section" title="Available tags"
                helper="Assign an existing team tag with one click.">
                <div class="flex flex-wrap gap-2">
                    @foreach ($filteredTags as $tag)
                        <x-forms.button wire:key="available-tag-{{ $tag->id }}"
                            wire:click="addTag('{{ $tag->id }}', '{{ $tag->name }}')">
                            <x-reicon name="plus" class="size-3.5 text-coollabs dark:text-warning" />
                            {{ $tag->name }}
                        </x-forms.button>
                    @endforeach
                </div>
            </x-application.settings-section>
        @endif
    @endcan
</div>

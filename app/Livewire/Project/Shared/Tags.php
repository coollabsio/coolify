<?php

namespace App\Livewire\Project\Shared;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

// Refactored ✅
class Tags extends Component
{
    use AuthorizesRequests;

    public $resource = null;

    #[Validate('required|string|min:2')]
    public string $newTags;

    public $tags = [];

    public $filteredTags = [];

    public function mount()
    {
        $this->loadTags();
    }

    public function loadTags()
    {
        $this->tags = $this->resourceTags()->get();
        $this->filteredTags = $this->tags->filter(function ($tag) {
            return ! $this->resource->tags->contains($tag);
        });
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->resource);
            $this->validate();
            $tags = str($this->newTags)->trim()->explode(' ');
            foreach ($tags as $tag) {
                $tag = strip_tags($tag);
                if (strlen($tag) < 2) {
                    $this->dispatch('error', 'Invalid tag.', "Tag <span class='dark:text-warning'>$tag</span> is invalid. Min length is 2.");

                    continue;
                }
                if ($this->resource->tags()->where('name', $tag)->exists()) {
                    $this->dispatch('error', 'Duplicate tags.', "Tag <span class='dark:text-warning'>$tag</span> already added.");

                    continue;
                }
                $found = $this->resourceTags()->where('name', $tag)->first()
                    ?? Tag::create([
                        'name' => $tag,
                        'team_id' => $this->resource->team()->id,
                    ]);
                $this->resource->tags()->attach($found->id);
            }
            $this->refresh();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function addTag(string $id)
    {
        try {
            $this->authorize('update', $this->resource);
            $tag = $this->resourceTags()->findOrFail($id);
            if ($this->resource->tags()->whereKey($tag->id)->exists()) {
                $this->dispatch('error', 'Duplicate tags.', 'Tag <span class=\'dark:text-warning\'>'.e($tag->name).'</span> already added.');

                return;
            }
            $this->resource->tags()->attach($tag->id);
            $this->refresh();
            $this->dispatch('success', 'Tag added.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function deleteTag(string $id)
    {
        try {
            $this->authorize('update', $this->resource);
            $this->resource->tags()->detach($id);
            $found_more_tags = $this->resourceTags()->find($id);
            $found_more_tags?->deleteIfOrphaned();
            $this->refresh();
            $this->dispatch('success', 'Tag deleted.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    private function resourceTags(): Builder
    {
        return Tag::query()->where('team_id', $this->resource->team()?->id)->orderBy('name');
    }

    public function refresh()
    {
        $this->resource->refresh(); // Remove this when legacy_model_binding is false
        $this->loadTags();
        $this->reset('newTags');
    }
}

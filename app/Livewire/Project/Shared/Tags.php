<?php

namespace App\Livewire\Project\Shared;

use App\Models\Tag;
use Livewire\Component;

class Tags extends Component
{
    public $resource = null;

    public ?string $new_tag = null;

    public $tags = [];

    protected $listeners = [
        'refresh' => '$refresh',
    ];

    protected $rules = [
        'resource.tags.*.name' => 'required|string|min:2',
        'new_tag' => 'required|string|min:2',
    ];

    protected $validationAttributes = [
        'new_tag' => 'tag',
    ];

    public function mount()
    {
        $this->tags = Tag::ownedByCurrentTeam()->get();
    }

    public function addTag(string $id, string $name)
    {
        try {
            if ($this->resource->tags()->where('id', $id)->exists()) {
                $this->dispatch('error', 'Duplicate tags.', "Tag <span class='dark:text-warning'>$name</span> already added.");

                return;
            }
            $this->resource->tags()->syncWithoutDetaching($id);
            $this->refresh();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function deleteTag(string $id)
    {
        try {
            $this->resource->tags()->detach($id);

            $found_more_tags = Tag::where(['id' => $id, 'team_id' => currentTeam()->id])->first();
            if ($found_more_tags->applications()->count() == 0 && $found_more_tags->services()->count() == 0) {
                $found_more_tags->delete();
            }
            $this->refresh();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function refresh()
    {
        $this->resource->load(['tags']);
        $this->tags = Tag::ownedByCurrentTeam()->get();
        $this->new_tag = null;
    }

    public function submit()
    {
        try {
            $this->validate([
                'new_tag' => 'required|string|min:2',
            ]);
            $tags = str($this->new_tag)->trim()->explode(' ');
            foreach ($tags as $tag) {
                if ($this->resource->tags()->where('name', $tag)->exists()) {
                    $this->dispatch('error', 'Duplicate tags.', "Tag <span class='dark:text-warning'>$tag</span> already added.");

                    continue;
                }
                $found = Tag::where(['name' => $tag, 'team_id' => currentTeam()->id])->first();
                if (! $found) {
                    $found = Tag::create([
                        'name' => $tag,
                        'team_id' => currentTeam()->id,
                    ]);
                }
                $this->resource->tags()->syncWithoutDetaching($found->id);
            }
            $this->refresh();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.shared.tags');
    }
}

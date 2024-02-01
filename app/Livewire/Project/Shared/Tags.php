<?php

namespace App\Livewire\Project\Shared;

use App\Models\Tag;
use Livewire\Component;

class Tags extends Component
{
    public $resource = null;
    public ?string $new_tag = null;
    protected $listeners = [
        'refresh' => '$refresh',
    ];
    public function mount()
    {
    }
    public function deleteTag($id, $name)
    {
        try {
            $found_more_tags = Tag::where(['name' => $name, 'team_id' => currentTeam()->id])->first();
            $this->resource->tags()->detach($id);
            if ($found_more_tags->resources()->get()->count() == 0) {
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
        $this->new_tag = null;
    }
    public function submit()
    {
        try {
            $this->validate([
                'new_tag' => 'required|string|min:2'
            ]);
            $tags = str($this->new_tag)->trim()->explode(' ');
            foreach ($tags as $tag) {
                $found = Tag::where(['name' => $tag, 'team_id' => currentTeam()->id])->first();
                if (!$found) {
                    $found = Tag::create([
                        'name' => $tag,
                        'team_id' => currentTeam()->id
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

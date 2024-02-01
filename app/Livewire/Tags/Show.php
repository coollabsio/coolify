<?php

namespace App\Livewire\Tags;

use App\Models\Tag;
use Livewire\Component;

class Show extends Component
{
    public Tag $tag;
    public $resources;
    public $webhook = null;
    public function mount()
    {
        $tag = Tag::ownedByCurrentTeam()->where('name', request()->tag_name)->first();
        if (!$tag) {
            return redirect()->route('tags.index');
        }
        $this->webhook = generatTagDeployWebhook($tag->name);
        $this->resources = $tag->resources()->get();
        $this->tag = $tag;
    }
    public function render()
    {
        return view('livewire.tags.show');
    }
}
